<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\GatewayCounter;
use Revenexx\AppSdk\Router\Trash;
use Revenexx\AppSdk\Settings;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/** `Trash::mount` and `GatewayCounter` — the same cases the JavaScript SDK tests for `mountTrash`. */
final class TrashTest extends TestCase
{
    private const NOW = '2026-10-14T12:00:00Z';

    private const JULIA = [
        'x-revenexx-actor' => '301155668849',
        'x-revenexx-actor-email' => 'julia.koch@example.com',
        'x-revenexx-actor-trust' => 'authenticated',
    ];

    /** What `trash: true` gives an entity. */
    private const TRASH_COLUMNS = [
        'deleted_at' => ['type' => 'timestamptz'],
        'deleted_by' => ['type' => 'text'],
        'deleted_by_id' => ['type' => 'text'],
        'trash_batch' => ['type' => 'uuid'],
        'purge_blocked_reason' => ['type' => 'text'],
    ];

    private const OPS = ['list', 'get', 'create', 'update', 'delete'];

    private static function organizations(): Entity
    {
        return new Entity('organizations', 't_organizations', 'id', [
            'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
            'name' => ['type' => 'text', 'notNull' => true],
            ...self::TRASH_COLUMNS,
        ], self::OPS);
    }

    private static function contacts(): Entity
    {
        return new Entity('contacts', 't_contacts', 'id', [
            'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
            'organization_id' => ['type' => 'uuid', 'notNull' => true],
            'email' => ['type' => 'text', 'notNull' => true],
            ...self::TRASH_COLUMNS,
        ], self::OPS);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private static function daysAgo(int $n): string
    {
        return self::now()->sub(new \DateInterval("P{$n}D"))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<string, list<array<string, mixed>>> $seed
     * @param array<string, mixed>                      $extra
     * @return array{Client, Trash, App, Entity, Entity}
     */
    private static function build(array $seed = [], array $extra = []): array
    {
        $o = self::organizations();
        $c = self::contacts();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => [
            'organizations' => [['id' => 'o1', 'name' => 'Contoso']],
            'contacts' => [
                ['id' => 'c1', 'organization_id' => 'o1', 'email' => 'a@contoso.test'],
                ['id' => 'c2', 'organization_id' => 'o1', 'email' => 'b@contoso.test'],
            ],
            ...$seed,
        ]]), ['organizations' => $o, 'contacts' => $c]);
        $app = new App(['name' => 'customers']);
        $trash = Trash::mount($app, $db, $o, [
            'path' => '/organizations',
            'children' => [['name' => 'contacts', 'entity' => $c, 'column' => 'organization_id']],
            'now' => self::now(...),
            ...$extra,
        ]);
        Trash::mount($app, $db, $c, [
            'path' => '/contacts',
            'parent' => ['entity' => $o, 'column' => 'organization_id'],
            'now' => self::now(...),
        ]);

        return [$db, $trash, $app, $o, $c];
    }

    /**
     * A stand-in for the gateway's GET /v1/audit/settings.
     *
     * @param array{status: int, body?: mixed}|'down' $answer
     * @param list<array{url: string, headers: list<string>}> $calls
     */
    private static function auditSettings(array|string $answer, array &$calls = []): callable
    {
        return static function (string $url, array $headers) use ($answer, &$calls): array {
            $calls[] = ['url' => $url, 'headers' => $headers];
            if ($answer === 'down') {
                throw new \RuntimeException('connect ECONNREFUSED');
            }

            return ['status' => $answer['status'], 'body' => (string) json_encode($answer['body'] ?? new \stdClass())];
        };
    }

    // trash-routes AC-1 — A first DELETE puts the record and its group into the trash
    #[Group('spec:trash-routes:AC-1')]
    #[Group('spec:trash-routes')]
    public function testPutsTheRecordAndItsLiveChildrenIntoTheTrashAsOneGroup(): void
    {
        [$db, , $app, $o, $c] = self::build(['contacts' => [
            ['id' => 'c1', 'organization_id' => 'o1', 'email' => 'a@contoso.test'],
            ['id' => 'c2', 'organization_id' => 'o1', 'email' => 'b@contoso.test'],
            ['id' => 'c3', 'organization_id' => 'o1', 'email' => 'old@contoso.test', 'deleted_at' => self::daysAgo(3), 'trash_batch' => 'bbbbbbbb-0000-0000-0000-000000000003'],
        ]]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['trashed']);
        $this->assertSame(['contacts' => 2], $res['data']['took']);
        $org = $db->get($o, 'o1');
        $this->assertSame('2026-10-14T12:00:00.000Z', $org['deleted_at']);
        $this->assertSame('julia.koch@example.com', $org['deleted_by']);
        $this->assertSame('301155668849', $org['deleted_by_id']);
        $this->assertSame($res['data']['batch'], $org['trash_batch']);
        $this->assertSame($res['data']['batch'], $db->get($c, 'c1')['trash_batch']);
        $this->assertSame($res['data']['batch'], $db->get($c, 'c2')['trash_batch']);
        $this->assertSame('bbbbbbbb-0000-0000-0000-000000000003', $db->get($c, 'c3')['trash_batch']);
    }

    // trash-routes AC-2 — A guard refuses the trash with its reasons
    #[Group('spec:trash-routes:AC-2')]
    #[Group('spec:trash-routes')]
    public function testRefusesTheTrashWhenTheGuardNamesAReason(): void
    {
        [$db, , $app, $o] = self::build([], ['guard' => static fn (): array => ['reason' => 'the default organization stays']]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(409, $res['status']);
        $this->assertSame('trash_blocked', $res['data']['code']);
        $this->assertSame([['reason' => 'the default organization stays']], $res['data']['details']['blockers']);
        $this->assertNull($db->get($o, 'o1')['deleted_at'] ?? null);
    }

    // trash-routes AC-3 — A second DELETE deletes the record and its group for good
    #[Group('spec:trash-routes:AC-3')]
    #[Group('spec:trash-routes')]
    public function testDeletesTheRecordAndTheGroupItTookOnTheSecondDelete(): void
    {
        [$db, , $app, $o, $c] = self::build();
        FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['deleted']);
        $this->assertSame(['contacts' => 2], $res['data']['took']);
        $this->assertNull($db->get($o, 'o1'));
        $this->assertSame([], $db->list($c));
    }

    // trash-routes AC-4 — A blocker refuses the final delete with its reasons
    #[Group('spec:trash-routes:AC-4')]
    #[Group('spec:trash-routes')]
    public function testKeepsATrashedRecordThatABlockerHolds(): void
    {
        [$db, , $app, $o] = self::build([], ['blockers' => static fn (): array => [['reason' => '2 open orders', 'count' => 2], null]]);
        FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(409, $res['status']);
        $this->assertSame('delete_blocked', $res['data']['code']);
        $this->assertSame([['reason' => '2 open orders', 'count' => 2]], $res['data']['details']['blockers']);
        $this->assertNotNull($db->get($o, 'o1'));
    }

    // trash-routes AC-5 — A check that fails blocks
    #[Group('spec:trash-routes:AC-5')]
    #[Group('spec:trash-routes')]
    public function testTreatsACheckThatThrowsAsABlocker(): void
    {
        [, , $app] = self::build([], ['guard' => static function (): never {
            throw new \RuntimeException('approvals did not answer');
        }]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(409, $res['status']);
        $this->assertSame('unverifiable', $res['data']['details']['blockers'][0]['code']);
        $this->assertStringContainsString('approvals did not answer', $res['data']['details']['blockers'][0]['reason']);
    }

    // trash-routes AC-6 — Deleting for good can need its own permission
    #[Group('spec:trash-routes:AC-6')]
    #[Group('spec:trash-routes')]
    public function testRefusesTheFinalDeleteToACallerWithoutThePermission(): void
    {
        [$db, , $app, $o] = self::build([], ['canDeleteForGood' => static fn (mixed $c): bool => $c->actor->holds('customers.purge')]);
        FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $res = FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $this->assertSame(403, $res['status']);
        $this->assertSame('not_permitted', $res['data']['code']);
        $this->assertNotNull($db->get($o, 'o1'));
    }

    // trash-routes AC-7 — A restore brings back the record and the group it took
    #[Group('spec:trash-routes:AC-7')]
    #[Group('spec:trash-routes')]
    public function testRestoresTheRecordWithItsGroupAndRefusesWhatItCannotRestore(): void
    {
        [$db, , $app, $o, $c] = self::build();
        FakeContext::call($app, 'DELETE', '/contacts/c2', ['headers' => self::JULIA]);
        FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);

        $orphan = FakeContext::call($app, 'POST', '/contacts/c1/restore', ['headers' => self::JULIA]);
        $this->assertSame(409, $orphan['status']);
        $this->assertSame('parent_in_trash', $orphan['data']['code']);

        $res = FakeContext::call($app, 'POST', '/organizations/o1/restore', ['headers' => self::JULIA]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['restored']);
        $this->assertSame(['contacts' => 1], $res['data']['took']);
        $org = $db->get($o, 'o1');
        $this->assertNull($org['deleted_at']);
        $this->assertNull($org['trash_batch']);
        $this->assertNull($org['deleted_by']);
        $this->assertNull($db->get($c, 'c1')['deleted_at']);
        $this->assertNotNull($db->get($c, 'c2')['deleted_at']);

        $live = FakeContext::call($app, 'POST', '/organizations/o1/restore', ['headers' => self::JULIA]);
        $this->assertSame(409, $live['status']);
        $this->assertSame('not_in_trash', $live['data']['code']);
    }

    // trash-routes AC-8 — The purge deletes what is past the tenant's retention, through the blockers
    #[Group('spec:trash-routes:AC-8')]
    #[Group('spec:trash-routes')]
    public function testPurgesPastTheRetentionAndKeepsWhatABlockerHolds(): void
    {
        $calls = [];
        [$db, $trash, , $o, $c] = self::build([
            'organizations' => [
                ['id' => 'old', 'name' => 'Old', 'deleted_at' => self::daysAgo(31), 'trash_batch' => 'aaaaaaaa-0000-0000-0000-000000000001'],
                ['id' => 'held', 'name' => 'Held', 'deleted_at' => self::daysAgo(40), 'trash_batch' => 'aaaaaaaa-0000-0000-0000-000000000002'],
                ['id' => 'young', 'name' => 'Young', 'deleted_at' => self::daysAgo(29), 'trash_batch' => 'aaaaaaaa-0000-0000-0000-000000000003'],
                ['id' => 'live', 'name' => 'Live'],
            ],
            'contacts' => [['id' => 'c9', 'organization_id' => 'old', 'email' => 'x@old.test', 'deleted_at' => self::daysAgo(31), 'trash_batch' => 'aaaaaaaa-0000-0000-0000-000000000001']],
        ], [
            'blockers' => static fn (array $org): ?array => $org['id'] === 'held' ? ['reason' => '1 open quote', 'count' => 1] : null,
            'retention' => ['transport' => self::auditSettings(['status' => 200, 'body' => ['trash_retention_days' => 30, 'defaults' => false]], $calls), 'endpoint' => 'https://api.test', 'env' => []],
        ]);

        $result = $trash->purge(['x-revenexx-tenant' => 'acme', 'x-revenexx-context' => 'ctx-token']);

        $this->assertSame('https://api.test/v1/audit/settings', $calls[0]['url']);
        $this->assertContains('X-Revenexx-Tenant: acme', $calls[0]['headers']);
        $this->assertContains('Authorization: Bearer ctx-token', $calls[0]['headers']);
        $this->assertFalse($result['skipped']);
        $this->assertSame(30, $result['retentionDays']);
        $this->assertSame(['old'], $result['purged']);
        $this->assertSame([['id' => 'held', 'blockers' => [['reason' => '1 open quote', 'count' => 1]]]], $result['blocked']);
        $this->assertNull($db->get($o, 'old'));
        $this->assertNull($db->get($c, 'c9'));
        // Into `purge_blocked_reason` without naming it: `trash: true` creates it.
        $this->assertSame('1 open quote', $db->get($o, 'held')['purge_blocked_reason']);
        $this->assertNotNull($db->get($o, 'young'));
        $this->assertNotNull($db->get($o, 'live'));
    }

    // trash-routes AC-8 — The purge deletes what is past the tenant's retention, through the blockers
    #[Group('spec:trash-routes:AC-8')]
    #[Group('spec:trash-routes')]
    public function testPurgesAfter90DaysWhenTheTenantHasNoRetentionSet(): void
    {
        [$db, $trash, , $o] = self::build(['organizations' => [
            ['id' => 'o89', 'name' => 'A', 'deleted_at' => self::daysAgo(89)],
            ['id' => 'o91', 'name' => 'B', 'deleted_at' => self::daysAgo(91)],
        ]], ['retention' => ['transport' => self::auditSettings(['status' => 200, 'body' => []]), 'env' => []]]);

        $result = $trash->purge(['x-revenexx-tenant' => 'acme']);

        $this->assertSame(90, Trash::DEFAULT_RETENTION_DAYS);
        $this->assertSame(90, $result['retentionDays']);
        $this->assertSame(['o91'], $result['purged']);
        $this->assertNotNull($db->get($o, 'o89'));
    }

    // trash-routes AC-8 — The purge deletes what is past the tenant's retention, through the blockers
    #[Group('spec:trash-routes:AC-8')]
    #[Group('spec:trash-routes')]
    public function testWritesAReasonOnlyWhenItChangedClearsItOnRestoreAndCanBeSwitchedOff(): void
    {
        $transport = self::auditSettings(['status' => 200, 'body' => ['trash_retention_days' => 30]]);
        [$db, $trash, $app, $o] = self::build(
            ['organizations' => [['id' => 'held', 'name' => 'Held', 'deleted_at' => self::daysAgo(40), 'purge_blocked_reason' => '1 open quote']]],
            ['blockers' => static fn (): array => ['reason' => '1 open quote'], 'retention' => ['transport' => $transport, 'env' => []]],
        );
        $before = $db->get($o, 'held');
        $trash->purge(['x-revenexx-tenant' => 'acme']);
        $this->assertSame($before, $db->get($o, 'held'));

        FakeContext::call($app, 'POST', '/organizations/held/restore', ['headers' => self::JULIA]);
        $this->assertNull($db->get($o, 'held')['purge_blocked_reason']);

        [$offDb, $off, , $offO] = self::build(
            ['organizations' => [['id' => 'held', 'name' => 'Held', 'deleted_at' => self::daysAgo(40)]]],
            ['blockers' => static fn (): array => ['reason' => '1 open quote'], 'purgeReasonColumn' => false, 'retention' => ['transport' => $transport, 'env' => []]],
        );
        $off->purge(['x-revenexx-tenant' => 'acme']);
        $this->assertNull($offDb->get($offO, 'held')['purge_blocked_reason'] ?? null);
    }

    // trash-routes AC-9 — The purge does nothing when the retention cannot be read
    #[Group('spec:trash-routes:AC-9')]
    #[Group('spec:trash-routes')]
    public function testSkipsTheRunWhenTheRetentionCannotBeRead(): void
    {
        foreach (['down', ['status' => 503], ['status' => 404]] as $answer) {
            [$db, $trash, , $o] = self::build(
                ['organizations' => [['id' => 'ancient', 'name' => 'A', 'deleted_at' => self::daysAgo(4000)]]],
                ['retention' => ['transport' => self::auditSettings($answer), 'env' => []]],
            );

            $result = $trash->purge(['x-revenexx-tenant' => 'acme']);

            $this->assertTrue($result['skipped']);
            $this->assertNotEmpty($result['reason']);
            $this->assertSame([], $result['purged']);
            $this->assertNotNull($db->get($o, 'ancient'));
        }
    }

    // trash-routes AC-10 — A cross-app count comes from the owning App's list
    #[Group('spec:trash-routes:AC-10')]
    #[Group('spec:trash-routes')]
    public function testCountsThroughTheOwningAppListAndTurnsNoAnswerIntoABlocker(): void
    {
        $answers = [
            '/v1/orders/open_orders' => ['status' => 200, 'body' => ['items' => [[]], 'page' => ['total' => 3]]],
            '/v1/quotes/open_quotes' => ['status' => 200, 'body' => ['items' => [], 'page' => ['total' => 0]]],
            '/v1/approvals/pending' => ['status' => 403, 'body' => ['error' => 'nope']],
            '/v1/punchout/accounts' => ['status' => 200, 'body' => ['items' => []]],
        ];
        $urls = [];
        $refs = new GatewayCounter(['endpoint' => 'https://api.test', 'env' => [], 'transport' => static function (string $url) use ($answers, &$urls): array {
            $urls[] = $url;
            $answer = $answers[(string) parse_url($url, PHP_URL_PATH)] ?? null;
            if ($answer === null) {
                throw new \RuntimeException('timeout');
            }

            return ['status' => $answer['status'], 'body' => (string) json_encode($answer['body'])];
        }]);
        $caller = ['x-revenexx-tenant' => 'acme', 'x-revenexx-context' => 'ctx'];

        $this->assertSame(3, $refs->count($caller, '/v1/orders/open_orders', ['organization_id' => 'o1']));
        $this->assertSame('https://api.test/v1/orders/open_orders?organization_id=o1&limit=1', $urls[0]);

        $this->assertSame(
            ['reason' => '3 open orders', 'count' => 3, 'code' => 'referenced'],
            $refs->blocker($caller, ['path' => '/v1/orders/open_orders', 'where' => ['organization_id' => 'o1'], 'reason' => 'open orders']),
        );
        $this->assertNull($refs->blocker($caller, ['path' => '/v1/quotes/open_quotes', 'reason' => 'open quotes']));
        // Refused, no total, unreachable: not knowing is never a pass.
        foreach (['/v1/approvals/pending', '/v1/punchout/accounts', '/v1/nowhere'] as $path) {
            $this->assertSame('unverifiable', $refs->blocker($caller, ['path' => $path, 'reason' => 'references'])['code']);
        }
    }

    // trash-routes AC-11 — Named events are fired by the App
    #[Group('spec:trash-routes:AC-11')]
    #[Group('spec:trash-routes')]
    public function testFiresTheNamedEvents(): void
    {
        $fired = [];
        [, $trash, $app] = self::build([], [
            'events' => [
                'emit' => static function (string $name, array $data, array $options) use (&$fired): void {
                    $fired[] = [$name, $data, $options['topicId'] ?? null];
                },
                'trashed' => 'organization.trashed',
                'restored' => 'organization.restored',
                'purged' => 'organizations.purged',
            ],
            'retention' => ['transport' => self::auditSettings(['status' => 200, 'body' => ['trash_retention_days' => 90]]), 'env' => []],
        ]);

        FakeContext::call($app, 'DELETE', '/organizations/o1', ['headers' => self::JULIA]);
        FakeContext::call($app, 'POST', '/organizations/o1/restore', ['headers' => self::JULIA]);
        $trash->purge(['x-revenexx-tenant' => 'acme']);

        $this->assertSame(
            [['organization.trashed', 'o1'], ['organization.restored', 'o1'], ['organizations.purged', null]],
            array_map(static fn (array $e): array => [$e[0], $e[2]], $fired),
        );
        $this->assertSame(90, $fired[2][1]['retention_days']);
        $this->assertSame(0, $fired[2][1]['purged']);
        $this->assertSame(0, $fired[2][1]['blocked']);
    }

    // trash-routes AC-12 — The retention is read through the gateway
    #[Group('spec:trash-routes:AC-12')]
    #[Group('spec:trash-routes')]
    public function testReadsTheRetentionFromTheGatewayOrFromTheOverrideUrl(): void
    {
        $this->assertSame(Settings::DEFAULT_GATEWAY_ENDPOINT . '/v1/audit/settings', Trash::auditSettingsUrl(['env' => []]));
        $this->assertSame('https://gw.test/v1/audit/settings', Trash::auditSettingsUrl(['env' => ['REVENEXX_GATEWAY_ENDPOINT' => 'https://gw.test/']]));
        $this->assertSame('http://audit.local/settings', Trash::auditSettingsUrl(['env' => [
            'REVENEXX_GATEWAY_ENDPOINT' => 'https://gw.test',
            'REVENEXX_AUDIT_SETTINGS_URL' => 'http://audit.local/settings',
        ]]));
        $this->assertSame('http://x.test/s', Trash::auditSettingsUrl(['url' => 'http://x.test/s', 'env' => ['REVENEXX_AUDIT_SETTINGS_URL' => 'http://audit.local/settings']]));

        $calls = [];
        $days = Trash::readRetention(
            ['x-revenexx-tenant' => 'acme', 'x-revenexx-context' => 'ctx'],
            ['transport' => self::auditSettings(['status' => 200, 'body' => ['trash_retention_days' => 14]], $calls), 'env' => []],
        );
        $this->assertSame(14, $days);
        $this->assertSame(Settings::DEFAULT_GATEWAY_ENDPOINT . '/v1/audit/settings', $calls[0]['url']);
        $this->assertContains('Authorization: Bearer ctx', $calls[0]['headers']);
    }
}
