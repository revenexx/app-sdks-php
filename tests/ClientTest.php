<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;

final class ClientTest extends TestCase
{
    private function greetings(array $ops = ['list', 'get', 'create', 'update', 'delete']): Entity
    {
        return new Entity(
            name: 'greetings',
            table: 'revenexx__sample_app__greetings',
            pk: 'id',
            columns: [
                'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
                'name' => ['type' => 'text', 'notNull' => true],
                'message' => ['type' => 'text', 'notNull' => true, 'default' => "'Hello'"],
                'locale' => ['type' => 'text', 'notNull' => true, 'default' => "'en'"],
                'created_at' => ['type' => 'timestamptz', 'notNull' => true, 'default' => 'now()'],
                'updated_at' => ['type' => 'timestamptz', 'notNull' => true, 'default' => 'now()'],
            ],
            ops: $ops,
        );
    }

    private function client(Entity $entity, array $seed = []): Client
    {
        return new Client(
            AdapterRegistry::create('mock', ['seed' => $seed]),
            [$entity->name => $entity],
        );
    }

    // mock-fidelity AC-1 — A created row carries its defaults and a key
    // data-client AC-12 — The plain operations answer rows
    #[Group('spec:mock-fidelity:AC-1')]
    #[Group('spec:data-client:AC-12')]
    #[Group('spec:data-client')]
    #[Group('spec:mock-fidelity')]
    public function testMockCreateAppliesDefaultsThenCrud(): void
    {
        $e = $this->greetings();
        $db = $this->client($e);

        $g = $db->create($e, ['name' => 'Max']);
        $this->assertNotEmpty($g['id']);
        $this->assertSame('Hello', $g['message']);
        $this->assertSame('en', $g['locale']);
        $this->assertNotEmpty($g['created_at']);

        $this->assertSame('Max', $db->get($e, $g['id'])['name']);
        $this->assertCount(1, $db->list($e, ['where' => ['name' => 'Max']]));

        $upd = $db->update($e, $g['id'], ['message' => 'Hi']);
        $this->assertSame('Hi', $upd['message']);
        $this->assertNotEmpty($upd['updated_at']);

        $db->remove($e, $g['id']);
        $this->assertNull($db->get($e, $g['id']));
    }

    // query-language AC-1 — A value alone means equality
    // query-language AC-3 — An operator object compares
    // query-language AC-5 — Order, limit, offset and select shape the answer
    // mock-fidelity AC-4 — Seeded rows are queried like stored ones
    #[Group('spec:query-language:AC-1')]
    #[Group('spec:query-language:AC-3')]
    #[Group('spec:query-language:AC-5')]
    #[Group('spec:mock-fidelity:AC-4')]
    #[Group('spec:mock-fidelity')]
    #[Group('spec:query-language')]
    public function testSeedFiltersOrderLimitSelect(): void
    {
        $e = $this->greetings();
        $db = $this->client($e, [
            'greetings' => [
                ['id' => '1', 'name' => 'a', 'message' => 'x', 'locale' => 'en', 'created_at' => '2026-01-01'],
                ['id' => '2', 'name' => 'b', 'message' => 'y', 'locale' => 'de', 'created_at' => '2026-01-02'],
                ['id' => '3', 'name' => 'c', 'message' => 'z', 'locale' => 'en', 'created_at' => '2026-01-03'],
            ],
        ]);

        $this->assertCount(2, $db->list($e, ['where' => ['locale' => 'en']]));

        $ordered = $db->list($e, ['order' => 'created_at.desc', 'limit' => 2, 'select' => ['id']]);
        $this->assertSame(['3', '2'], array_column($ordered, 'id'));
        $this->assertSame(['id'], array_keys($ordered[0]));

        $gte = $db->list($e, ['where' => ['created_at' => ['op' => 'gte', 'value' => '2026-01-02']]]);
        $this->assertCount(2, $gte);
    }

    // mock-fidelity AC-2 — A condition on an undeclared column fails like PostgREST
    #[Group('spec:mock-fidelity:AC-2')]
    #[Group('spec:mock-fidelity')]
    public function testMockRefusesAWhereOnAnUndeclaredColumnLikePostgrest(): void
    {
        $e = $this->greetings();
        $db = $this->client($e, ['greetings' => [['id' => '1', 'name' => 'a']]]);

        // tenant_id exists on every table without being declared.
        $this->assertSame([], $db->list($e, ['where' => ['tenant_id' => 't1']]));

        // The remote adapter maps PostgREST's 42703 to exactly this answer.
        try {
            $db->list($e, ['where' => ['organization_id' => 'o1']]);
            $this->fail('expected a 400');
        } catch (HttpError $err) {
            $this->assertSame([400, 'bad_request', 'the request could not be processed'], [$err->status, $err->errorCode, $err->getMessage()]);
            $this->assertSame(['organization_id'], $err->serverDetail['unknownColumns']);
        }
        $this->expectException(HttpError::class);
        $db->page($e, ['where' => ['organization_id' => 'o1']]);
    }

    // data-client AC-2 — An update with nothing to update is refused before any request
    #[Group('spec:data-client:AC-2')]
    #[Group('spec:data-client')]
    public function testUpdateWithAnEmptyPatchIsRejected(): void
    {
        $e = $this->greetings();
        $db = $this->client($e, ['greetings' => [['id' => '1', 'name' => 'a']]]);

        try {
            $db->update($e, '1', []);
            $this->fail('expected a 400');
        } catch (HttpError $err) {
            $this->assertSame([400, 'empty_update', Client::EMPTY_UPDATE_MESSAGE], [$err->status, $err->errorCode, $err->getMessage()]);
        }
    }

    // data-client AC-3 — An update that matched no row is not found
    #[Group('spec:data-client:AC-3')]
    #[Group('spec:data-client')]
    public function testRemoteUpdateThatTouchedNoRowIsNotAnEmptyRecord(): void
    {
        $e = $this->greetings();
        // PostgREST answers a write that touched no row with `200 []`.
        $adapter = new RemoteAdapter(
            endpoint: 'http://pgrst:3000',
            transport: static fn (): array => ['status' => 200, 'body' => '[]'],
        );

        try {
            $adapter->update($e, 'gone', ['message' => 'Hi']);
            $this->fail('expected a 404');
        } catch (HttpError $err) {
            $this->assertSame([404, 'not_found'], [$err->status, $err->errorCode]);
        }
    }

    // data-client AC-1 — Only the operations the manifest grants exist
    #[Group('spec:data-client:AC-1')]
    #[Group('spec:data-client')]
    public function testPermissionGatingBlocksUnpermittedOps(): void
    {
        $e = $this->greetings(['list', 'get']); // read-only
        $db = $this->client($e);

        $this->assertCount(0, $db->list($e));

        try {
            $db->create($e, ['name' => 'x']);
            $this->fail('expected a 403');
        } catch (HttpError $err) {
            $this->assertStringContainsString('greetings.create() is not permitted', $err->getMessage());
            $this->assertSame([403, 'not_permitted'], [$err->status, $err->errorCode]);
        }
    }
}
