<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;

/**
 * `updateIf($entity, $id, $where, $patch)` — compare-and-set, mirroring the TS
 * runtime (packages/sdk/test/conditional-update.test.ts).
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

    public function testMockWritesWhenTheConditionHolds(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $row = $db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']);

        $this->assertSame('placed', $row['status'] ?? null);
        $this->assertSame('placed', $db->get($e, 'o1')['status'] ?? null);
    }

    public function testMockReturnsNullAndWritesNothingWhenTheConditionFails(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNull($db->updateIf($e, 'o1', ['status' => 'draft'], ['status' => 'placed']));
        $this->assertSame('placing', $db->get($e, 'o1')['status'] ?? null);
    }

    public function testMockReturnsNullForAMissingRow(): void
    {
        $e = $this->orders();
        $this->assertNull($this->mock($e)->updateIf($e, 'nope', ['status' => 'placing'], ['status' => 'placed']));
    }

    public function testOfTwoTransitionsOutOfOneStateOnlyTheFirstWins(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNotNull($db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'abandoned']));
        $this->assertNull($db->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']));
    }

    public function testMockHonoursOperators(): void
    {
        $e = $this->orders();
        $db = $this->mock($e);

        $this->assertNull($db->updateIf($e, 'o1', ['total' => ['op' => 'gte', 'value' => 20]], ['total' => 30]));
        $this->assertSame(30, $db->updateIf($e, 'o1', ['status' => ['placing', 'draft']], ['total' => 30])['total'] ?? null);
    }

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

    public function testRemoteAnswersNullWhenNoRowMatched(): void
    {
        $e = $this->orders();
        $calls = [];
        $this->assertNull($this->remote($e, '[]', $calls)->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']));
    }

    public function testAnEmptyWhereIsRejectedBeforeAnyRequest(): void
    {
        $e = $this->orders();
        $calls = [];
        $db = $this->remote($e, '[]', $calls);

        try {
            $db->updateIf($e, 'o1', [], ['status' => 'placed']);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $err) {
            $this->assertSame(Client::EMPTY_CONDITION_MESSAGE, $err->getMessage());
        }
        $this->assertSame([], $calls);
    }

    public function testAnEmptyPatchIsRejected(): void
    {
        $e = $this->orders();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Client::EMPTY_UPDATE_MESSAGE);
        $this->mock($e)->updateIf($e, 'o1', ['status' => 'placing'], []);
    }

    public function testWithoutTheUpdatePermissionItIsRefused(): void
    {
        $e = $this->orders(['list', 'get']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/orders\.updateIf\(\) is not permitted/');
        $this->mock($e)->updateIf($e, 'o1', ['status' => 'placing'], ['status' => 'placed']);
    }

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
