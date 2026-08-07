<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;

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

        $db->remove($e, $g['id']);
        $this->assertNull($db->get($e, $g['id']));
    }

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

    public function testUpdateWithAnEmptyPatchIsRejected(): void
    {
        $e = $this->greetings();
        $db = $this->client($e, ['greetings' => [['id' => '1', 'name' => 'a']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Client::EMPTY_UPDATE_MESSAGE);
        $db->update($e, '1', []);
    }

    public function testRemoteUpdateThatTouchedNoRowIsNotAnEmptyRecord(): void
    {
        $e = $this->greetings();
        // PostgREST answers a write that touched no row with `200 []`.
        $adapter = new RemoteAdapter(
            endpoint: 'http://pgrst:3000',
            transport: static fn (): array => ['status' => 200, 'body' => '[]'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $adapter->update($e, 'gone', ['message' => 'Hi']);
    }

    public function testPermissionGatingBlocksUnpermittedOps(): void
    {
        $e = $this->greetings(['list', 'get']); // read-only
        $db = $this->client($e);

        $this->assertCount(0, $db->list($e));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not permitted/');
        $db->create($e, ['name' => 'x']);
    }
}
