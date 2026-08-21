<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\MockAdapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\EventDescriptor;

/**
 * `$client->events()->emit()` — the PHP mirror of the TS runtime's
 * `db.events.emit()`. Same check, same wire format, same wording, because the
 * two runtimes are one contract with two proof points: an emitter that lies
 * about either side is worse than no emitter.
 */
final class EventsTest extends TestCase
{
    /** @return array<string, EventDescriptor> */
    private function declared(): array
    {
        return [
            'stock.low' => new EventDescriptor(
                topic: 'revenexx.inventories.stock.low',
                sample: ['sku' => 'ACME-4711-BLK', 'available' => 3],
                schema: ['type' => 'object', 'required' => ['sku']],
            ),
            'stocktake.completed' => new EventDescriptor(topic: 'revenexx.inventories.stocktake.completed'),
        ];
    }

    private function stockLevels(): Entity
    {
        return new Entity(
            name: 'stock_levels',
            table: 'revenexx__inventories__stock_levels',
            pk: 'id',
            columns: ['id' => ['type' => 'uuid', 'pk' => true]],
            ops: ['list', 'get'],
        );
    }

    private function mockClient(): Client
    {
        $entity = $this->stockLevels();

        return new Client(
            new MockAdapter(),
            [$entity->name => $entity],
            'revenexx__inventories__emit',
            $this->declared(),
        );
    }

    public function test_an_app_without_declared_events_has_no_routine_to_call(): void
    {
        $entity = $this->stockLevels();
        $client = new Client(new MockAdapter(), [$entity->name => $entity]);

        $this->expectExceptionMessageMatches('/declares no app-fired events/');
        $client->events();
    }

    public function test_it_refuses_an_undeclared_event_and_names_the_declared_ones(): void
    {
        $client = $this->mockClient();

        $this->expectExceptionMessageMatches('/not declared by this app.*stock\.low, stocktake\.completed/s');
        $client->events()->emit('stock.exploded');
    }

    public function test_it_records_instead_of_publishing_on_the_mock_adapter(): void
    {
        $client = $this->mockClient();

        $client->events()->emit('stock.low', ['sku' => 'A-1', 'available' => 2], ['topicId' => 'A-1']);
        $client->events()->emit('stocktake.completed', ['counted' => 812]);

        $emitted = $client->events()->emitted();

        self::assertCount(2, $emitted);
        self::assertSame('stock.low', $emitted[0]['event']);
        self::assertSame('revenexx.inventories.stock.low', $emitted[0]['topic']);
        self::assertSame(['sku' => 'A-1', 'available' => 2], $emitted[0]['data']);
        self::assertSame('A-1', $emitted[0]['options']['topicId']);
        self::assertStringStartsWith('evt_', $emitted[0]['result']['id']);
    }

    public function test_a_repeated_id_is_the_no_op_the_outbox_makes_it(): void
    {
        $client = $this->mockClient();

        $first = $client->events()->emit('stock.low', ['available' => 3], ['id' => 'evt_fixed']);
        $second = $client->events()->emit('stock.low', ['available' => 2], ['id' => 'evt_fixed']);

        self::assertSame('evt_fixed', $first['id']);
        self::assertSame('evt_fixed', $second['id']);

        $emitted = $client->events()->emitted();
        self::assertCount(1, $emitted);
        // The FIRST payload stands, as it does in the outbox: by the time a
        // retry arrives the relay may already have published the original.
        self::assertSame(['available' => 3], $emitted[0]['data']);
    }

    public function test_the_remote_adapter_calls_the_routine_with_the_routine_argument_names(): void
    {
        $captured = [];
        $adapter = new RemoteAdapter(
            endpoint: 'https://apps.api.revenexx.com',
            token: 'ctx-token',
            tenant: 'acme-eu',
            transport: function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
                $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return ['status' => 200, 'body' => '{"id":"evt_x","topic":"revenexx.inventories.stock.low"}'];
            },
        );

        $entity = $this->stockLevels();
        $client = new Client($adapter, [$entity->name => $entity], 'revenexx__inventories__emit', $this->declared());

        $result = $client->events()->emit(
            'stock.low',
            ['sku' => 'ACME-4711-BLK'],
            ['topicId' => 'ACME-4711-BLK', 'id' => 'evt_low_20260819', 'metadata' => ['reason' => 'scan']],
        );

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://apps.api.revenexx.com/rpc/revenexx__inventories__emit', $captured['url']);
        // PostgREST maps body keys onto named parameters, so these are the
        // routine's names: `event_id`, not `id`, because a parameter called
        // `id` would shadow the outbox column in its ON CONFLICT clause.
        self::assertSame([
            'event' => 'stock.low',
            'data' => ['sku' => 'ACME-4711-BLK'],
            'topic_id' => 'ACME-4711-BLK',
            'metadata' => ['reason' => 'scan'],
            'event_id' => 'evt_low_20260819',
        ], json_decode((string) $captured['body'], true));

        // Nothing identifies the app: the routine is per app and only its role
        // may execute it, so the data-plane token is the whole credential.
        self::assertContains('Authorization: Bearer ctx-token', $captured['headers']);
        self::assertContains('X-Revenexx-Tenant: acme-eu', $captured['headers']);

        self::assertSame(['id' => 'evt_x', 'topic' => 'revenexx.inventories.stock.low'], $result);
    }

    public function test_an_empty_payload_goes_out_as_an_object_not_an_array(): void
    {
        $captured = null;
        $adapter = new RemoteAdapter(
            endpoint: 'https://x',
            transport: function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
                $captured = $body;

                return ['status' => 200, 'body' => '{"id":"evt_x","topic":"t"}'];
            },
        );

        $entity = $this->stockLevels();
        $client = new Client($adapter, [$entity->name => $entity], 'revenexx__inventories__emit', $this->declared());
        $client->events()->emit('stocktake.completed');

        // `data` and `metadata` are jsonb objects on the routine, and PHP's
        // empty array encodes as `[]`. Sending an array where an object is
        // declared is the kind of thing that only fails in production.
        self::assertStringContainsString('"data":{}', (string) $captured);
        self::assertStringContainsString('"metadata":{}', (string) $captured);
    }

    public function test_an_answer_without_an_event_id_is_an_error_not_a_null(): void
    {
        $adapter = new RemoteAdapter(
            endpoint: 'https://x',
            transport: static fn (): array => ['status' => 200, 'body' => '{"ok":true}'],
        );

        $entity = $this->stockLevels();
        $client = new Client($adapter, [$entity->name => $entity], 'revenexx__inventories__emit', $this->declared());

        $this->expectExceptionMessageMatches('/returned no event id/');
        $client->events()->emit('stock.low');
    }

    public function test_an_adapter_that_cannot_reach_the_bus_says_so(): void
    {
        $adapter = new RemoteAdapter(endpoint: 'https://x', transport: static fn (): array => ['status' => 200, 'body' => '{}']);
        $entity = $this->stockLevels();
        $client = new Client($adapter, [$entity->name => $entity], 'revenexx__inventories__emit', $this->declared());

        $this->expectExceptionMessageMatches("/only available on the 'mock' adapter/");
        $client->events()->emitted();
    }
}
