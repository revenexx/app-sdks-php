<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;

/**
 * `updateIf($entity, $id, $where, $patch)` — compare-and-set, the same cases the
 * JavaScript SDK tests.
 */
final class ConditionalUpdateTest extends TestCase
{
    private function orders(array $ops = ['list', 'get', 'create', 'update', 'delete']): Entity
    {
        return new Entity(
            name: 'orders',
            table: 'revenexx__procurement__orders',
            pk: 'id',
            columns: ['id' => ['type' => 'uuid', 'pk' => true], 'status' => ['type' => 'text'], 'total' => ['type' => 'numeric']],
            ops: $ops,
        );
    }

    private function mock(Entity $entity): Client
    {
        return new Client(
            AdapterRegistry::create('mock', ['seed' => ['orders' => [['id' => 'o1', 'status' => 'placing', 'total' => 10]]]]),
            [$entity->name => $entity],
        );
    }

    /** @param list<array{method: string, url: string, body: ?string}> $calls */
    private function remote(Entity $entity, string $answer, array &$calls): Client
    {
        $adapter = new RemoteAdapter(
            endpoint: 'http://pgrst:3000',
            transport: static function (string $method, string $url, array $headers, ?string $body) use ($answer, &$calls): array {
                $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

                return ['status' => 200, 'body' => $answer];
            },
        );

        return new Client($adapter, [$entity->name => $entity]);
    }

    // data-client AC-5 — A conditional update writes only while the condition holds
    #[Group('spec:data-client:AC-5')]
    #[Group('spec:data-client')]
    public function testMockWritesWhenTheConditionHolds(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $row = $db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']);

        $this->assertSame('placed', $row['status'] ?? null);
        $this->assertSame('placed', $db->get($e, 'o1')['status'] ?? null);
    }

    // data-client AC-6 — A lost condition answers null, not an error
    #[Group('spec:data-client:AC-6')]
    #[Group('spec:data-client')]
    public function testMockReturnsNullAndWritesNothingWhenTheConditionFails(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNull($db->updateIf($e, 'o1', ['status' => 'draft'], ['status' => 'placed']));
        $this->assertSame('placing', $db->get($e, 'o1')['status'] ?? null);
    }

    // mock-fidelity AC-2 — A condition on an undeclared column fails like PostgREST
    #[Group('spec:mock-fidelity:AC-2')]
    #[Group('spec:mock-fidelity')]
    public function testMockRefusesAConditionOnAnUndeclaredColumnLikePostgrest(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        try {
            $db->updateIf($e, 'o1', ['stauts' => 'placing'], ['status' => 'placed']);
            self::fail('expected a 400');
        } catch (HttpError $ex) {
            self::assertSame([400, 'bad_request'], [$ex->status, $ex->errorCode]);
        }
        self::assertSame('placing', $db->get($e, 'o1')['status']);
    }

    // data-client AC-6 — A lost condition answers null, not an error
    #[Group('spec:data-client:AC-6')]
    #[Group('spec:data-client')]
    public function testMockReturnsNullForAMissingRow(): void
    {
        $e = $this->orders();
        $this->assertNull($this->mock($e)->updateIf($e, 'nope', ['status' => 'placing'], ['status' => 'placed']));
    }

    // data-client AC-7 — Of two racing transitions out of one state, exactly one wins
    // mock-fidelity AC-5 — A conditional update cannot be interleaved
    #[Group('spec:data-client:AC-7')]
    #[Group('spec:mock-fidelity:AC-5')]
    #[Group('spec:data-client')]
    #[Group('spec:mock-fidelity')]
    public function testOfTwoTransitionsOutOfOneStateOnlyTheFirstWins(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNotNull($db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'abandoned']));
        $this->assertNull($db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']));
    }

    // data-client AC-5 — A conditional update writes only while the condition holds
    // query-language AC-2 — A list of values means any of them
    #[Group('spec:data-client:AC-5')]
    #[Group('spec:query-language:AC-2')]
    #[Group('spec:data-client')]
    #[Group('spec:query-language')]
    public function testMockHonoursOperators(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNull($db->updateIf($e, 'o1', ['total' => ['op' => 'gte', 'value' => 20]], ['total' => 30]));
        $this->assertSame(30, $db->updateIf($e, 'o1', ['status' => ['placing', 'draft']], ['total' => 30])['total'] ?? null);
    }

    // data-client AC-7 — Of two racing transitions out of one state, exactly one wins
    #[Group('spec:data-client:AC-7')]
    #[Group('spec:data-client')]
    public function testRemoteSendsOnePatchWithPkAndCondition(): void
    {
        $e = $this->orders();
        $calls = [];
        $db = $this->remote($e, '[{"id":"o1","status":"placed"}]', $calls);

        $row = $db->updateIf($e, 'o1', ['status' => 'placing', 'total' => ['op' => 'gte', 'value' => 5]], ['status' => 'placed']);

        $this->assertSame('placed', $row['status'] ?? null);
        $this->assertCount(1, $calls);
        $this->assertSame('PATCH', $calls[0]['method']);
        $this->assertSame('http://pgrst:3000/revenexx__procurement__orders?id=eq.o1&status=eq.placing&total=gte.5', $calls[0]['url']);
        $this->assertSame('{"status":"placed"}', $calls[0]['body']);
    }

    // data-client AC-6 — A lost condition answers null, not an error
    #[Group('spec:data-client:AC-6')]
    #[Group('spec:data-client')]
    public function testRemoteAnswersNullWhenNoRowMatched(): void
    {
        $e = $this->orders();
        $calls = [];
        $this->assertNull($this->remote($e, '[]', $calls)->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']));
    }

    // data-client AC-8 — A conditional update without a condition is refused
    #[Group('spec:data-client:AC-8')]
    #[Group('spec:data-client')]
    public function testAnEmptyWhereIsRejectedBeforeAnyRequest(): void
    {
        $e = $this->orders();
        $calls = [];
        $db = $this->remote($e, '[]', $calls);

        try {
            $db->updateIf($e, 'o1', [], ['status' => 'placed']);
            $this->fail('expected a 400');
        } catch (HttpError $err) {
            $this->assertSame([400, 'empty_condition', Client::EMPTY_CONDITION_MESSAGE], [$err->status, $err->errorCode, $err->getMessage()]);
        }
        $this->assertSame([], $calls);
    }

    // data-client AC-2 — An update with nothing to update is refused before any request
    #[Group('spec:data-client:AC-2')]
    #[Group('spec:data-client')]
    public function testAnEmptyPatchIsRejected(): void
    {
        $e = $this->orders();

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage(Client::EMPTY_UPDATE_MESSAGE);
        $this->mock($e)->updateIf($e, 'o1', ['status' => 'placing'], []);
    }

    // data-client AC-1 — Only the operations the manifest grants exist
    #[Group('spec:data-client:AC-1')]
    #[Group('spec:data-client')]
    public function testWithoutTheUpdatePermissionItIsRefused(): void
    {
        $e = $this->orders(['list', 'get']);

        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('/orders\.updateIf\(\) is not permitted/');
        $this->mock($e)->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']);
    }

    // data-client AC-9 — An adapter that cannot update conditionally says so
    #[Group('spec:data-client:AC-9')]
    #[Group('spec:data-client')]
    public function testAnAdapterWithoutItFailsInsteadOfEmulating(): void
    {
        $e = $this->orders();
        $plain = new class () implements Adapter {
            public function kind(): string { return 'no-cas'; }
            public function list(Entity $entity, array $query = []): array { return []; }
            public function get(Entity $entity, int|string $id): ?array { return null; }
            public function create(Entity $entity, array $data): array { return $data; }
            public function update(Entity $entity, int|string $id, array $patch): array { return $patch; }
            public function remove(Entity $entity, int|string $id): void {}
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("orders.updateIf() is not supported by the 'no-cas' adapter");
        (new Client($plain, ['orders' => $e]))->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']);
    }
}
