<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\Adapter\RuntimeAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * The remote and runtime adapters against recorded PostgREST answers — the same
 * cases the JavaScript SDK tests.
 */
final class DataPlaneTest extends TestCase
{
    private const INTERNAL_MARKERS = ['revenexx__', 'idx__', 'fk__', 'chk__', 'constraint', 'relation', 'tenant_id', 'market_id', 'Failing row', 'PGRST', '23505', '23503', '22P02'];

    private const DUPLICATE = ['status' => 409, 'body' => '{"code":"23505","details":"Key (tenant_id, code)=(3f2a, web) already exists.","hint":null,"message":"duplicate key value violates unique constraint \"idx__revenexx__channels__channels__tenant_id_code__uniq\""}'];
    private const FK_MISSING = ['status' => 409, 'body' => '{"code":"23503","details":"Key (market_id)=(0) is not present in table \"revenexx__markets__markets\".","message":"insert or update on table \"revenexx__markets__locales\" violates foreign key constraint \"fk__revenexx__markets__locales__market_id\""}'];
    private const FK_BLOCKED = ['status' => 409, 'body' => '{"code":"23503","details":"Key (id)=(9b1c) is still referenced from table \"revenexx__markets__locales\".","message":"update or delete on table \"revenexx__markets__markets\" violates foreign key constraint \"fk__x\""}'];
    private const BAD_UUID = ['status' => 400, 'body' => '{"code":"22P02","details":null,"hint":null,"message":"invalid input syntax for type uuid: \"not-a-uuid\""}'];
    private const NOT_NULL = ['status' => 400, 'body' => '{"code":"23502","details":"Failing row contains (9b1c, null).","message":"null value in column \"code\" of relation \"revenexx__channels__channels\" violates not-null constraint"}'];
    private const CHECK = ['status' => 400, 'body' => '{"code":"23514","message":"new row for relation \"revenexx__channels__channels\" violates check constraint \"chk__x\""}'];

    private static function channels(): Entity
    {
        return new Entity('channels', 'revenexx__channels__channels', 'id', ['id' => ['type' => 'uuid', 'pk' => true], 'code' => ['type' => 'text', 'notNull' => true]], ['list', 'get', 'create', 'update', 'delete']);
    }

    /**
     * @param array{status: int, body: string, headers?: array<string, string>} ...$answers the last repeats
     */
    private static function db(array ...$answers): Client
    {
        $adapter = new RemoteAdapter('http://pgrst:3000', transport: static function () use (&$answers): array {
            return \count($answers) > 1 ? array_shift($answers) : $answers[0];
        });

        return new Client($adapter, ['channels' => self::channels()]);
    }

    private static function caught(callable $call): HttpError
    {
        try {
            $call();
        } catch (HttpError $e) {
            return $e;
        }
        self::fail('expected the call to throw');
    }

    private static function assertNoInternals(mixed $payload): void
    {
        $text = strtolower((string) json_encode($payload));
        foreach (self::INTERNAL_MARKERS as $marker) {
            self::assertStringNotContainsString(strtolower($marker), $text);
        }
    }

    // data-plane-errors AC-1 — A duplicate is a 409
    #[Group('spec:data-plane-errors:AC-1')]
    #[Group('spec:data-plane-errors')]
    public function testADuplicateIsA409(): void
    {
        $e = self::channels();
        $err = self::caught(static fn () => self::db(self::DUPLICATE)->create($e, ['code' => 'web']));
        $this->assertSame([409, 'unique_violation', 'a record with this value already exists'], [$err->status, $err->errorCode, $err->getMessage()]);
    }

    // data-plane-errors AC-2 — A missing parent is a 400, a blocking child a 409
    #[Group('spec:data-plane-errors:AC-2')]
    #[Group('spec:data-plane-errors')]
    public function testAMissingParentIs400AndABlockingChild409(): void
    {
        $e = self::channels();
        $this->assertSame([400, 'foreign_key_violation'], [($x = self::caught(static fn () => self::db(self::FK_MISSING)->create($e, ['code' => 'x'])))->status, $x->errorCode]);
        $this->assertSame(409, self::caught(static fn () => self::db(self::FK_BLOCKED)->remove($e, '9b1c'))->status);
        $vague = ['status' => 409, 'body' => '{"code":"23503","message":"foreign key violation"}'];
        $this->assertSame(409, self::caught(static fn () => self::db($vague)->remove($e, '9b1c'))->status);
        $this->assertSame(400, self::caught(static fn () => self::db($vague)->create($e, ['code' => 'x']))->status);
    }

    // data-plane-errors AC-3 — A malformed value is a 400
    #[Group('spec:data-plane-errors:AC-3')]
    #[Group('spec:data-plane-errors')]
    public function testAMalformedValueIsA400(): void
    {
        $e = self::channels();
        $err = self::caught(static fn () => self::db(self::BAD_UUID)->get($e, 'not-a-uuid'));
        $this->assertSame([400, 'invalid_value'], [$err->status, $err->errorCode]);
    }

    // data-plane-errors AC-4 — A missing required value or a failed check is a 400
    #[Group('spec:data-plane-errors:AC-4')]
    #[Group('spec:data-plane-errors')]
    public function testAMissingValueOrAFailedCheckIsA400(): void
    {
        $e = self::channels();
        $this->assertSame([400, 'not_null_violation'], [($a = self::caught(static fn () => self::db(self::NOT_NULL)->create($e, [])))->status, $a->errorCode]);
        $this->assertSame([400, 'check_violation'], [($b = self::caught(static fn () => self::db(self::CHECK)->create($e, ['code' => 'bogus'])))->status, $b->errorCode]);
    }

    // data-plane-errors AC-5 — No internal name reaches the caller
    #[Group('spec:data-plane-errors:AC-5')]
    #[Group('spec:data-plane-errors')]
    public function testNoInternalNameReachesTheCaller(): void
    {
        $e = self::channels();
        foreach ([self::DUPLICATE, self::FK_MISSING, self::BAD_UUID, self::NOT_NULL, self::CHECK] as $answer) {
            $err = self::caught(static fn () => self::db($answer)->create($e, ['code' => 'x']));
            self::assertNoInternals($err->body());
        }
        $dup = self::caught(static fn () => self::db(self::DUPLICATE)->create($e, ['code' => 'web']));
        $this->assertStringContainsString('idx__revenexx__channels__channels__tenant_id_code__uniq', (string) json_encode($dup->serverDetail));

        $app = new App(['name' => 'channels']);
        Crud::mount($app, self::db(self::DUPLICATE), $e, ['path' => '/channels']);
        $res = FakeContext::call($app, 'POST', '/channels', ['body' => ['code' => 'web']]);
        $this->assertStringContainsString('idx__revenexx__channels__channels__tenant_id_code__uniq', implode("\n", $res['logs']));
        self::assertNoInternals($res['data']);
    }

    // data-plane-errors AC-6 — Anything unmapped is sanitised
    #[Group('spec:data-plane-errors:AC-6')]
    #[Group('spec:data-plane-errors')]
    public function testAnythingUnmappedIsSanitised(): void
    {
        $e = self::channels();
        $weird = self::caught(static fn () => self::db(['status' => 400, 'body' => '{"code":"42703","message":"column revenexx__channels__channels.bogus does not exist"}'])->list($e));
        $this->assertSame(400, $weird->status);
        self::assertNoInternals($weird->body());
        $down = self::caught(static fn () => self::db(['status' => 503, 'body' => 'upstream connect error'])->list($e));
        $this->assertSame([500, 'the data service could not complete the request'], [$down->status, $down->getMessage()]);
    }

    // data-plane-errors AC-7 — A mounted route answers the mapped status
    #[Group('spec:data-plane-errors:AC-7')]
    #[Group('spec:data-plane-errors')]
    public function testAMountedRouteAnswersTheMappedStatus(): void
    {
        $e = self::channels();
        foreach ([[self::DUPLICATE, 'POST', '/channels', 409, 'unique_violation'], [self::BAD_UUID, 'GET', '/channels/not-a-uuid', 400, 'invalid_value'], [self::FK_MISSING, 'POST', '/channels', 400, 'foreign_key_violation']] as [$answer, $method, $path, $status, $code]) {
            $app = new App(['name' => 'channels']);
            Crud::mount($app, self::db($answer), $e, ['path' => '/channels']);
            $res = FakeContext::call($app, $method, $path, ['body' => ['code' => 'web']]);
            $this->assertSame([$status, $code], [$res['status'], $res['data']['code']], $path);
            self::assertNoInternals($res['data']);
        }
    }

    // data-client AC-4 — An insert that cannot be read back is a described failure
    #[Group('spec:data-client:AC-4')]
    #[Group('spec:data-client')]
    public function testAnInsertThatCannotBeReadBackIsADescribedFailure(): void
    {
        $e = self::channels();
        $err = self::caught(static fn () => self::db(['status' => 201, 'body' => '[]'])->create($e, ['code' => 'x']));
        $this->assertSame([500, 'empty_representation', 'the record was created but could not be read back'], [$err->status, $err->errorCode, $err->getMessage()]);
    }

    // data-client AC-10 — A page reports the total beside the rows
    // mock-fidelity AC-3 — A page counts every match, not the page
    #[Group('spec:data-client:AC-10')]
    #[Group('spec:mock-fidelity:AC-3')]
    #[Group('spec:data-client')]
    #[Group('spec:mock-fidelity')]
    public function testAPageReportsTheTotalBesideTheRows(): void
    {
        $e = self::channels();
        $page = self::db(['status' => 200, 'body' => '[{"id":"r1"}]', 'headers' => ['Content-Range' => '0-9/247']])->page($e, ['limit' => 10, 'offset' => 0]);
        $this->assertSame(['items' => [['id' => 'r1']], 'total' => 247, 'limit' => 10, 'offset' => 0], $page);

        $rows = array_map(static fn (int $i): array => ['id' => (string) $i, 'code' => $i % 2 ? 'de' : 'en'], range(0, 24));
        $mock = new Client(AdapterRegistry::create('mock', ['seed' => ['channels' => $rows]]), ['channels' => $e]);
        $p = $mock->page($e, ['where' => ['code' => 'en'], 'limit' => 5, 'offset' => 0]);
        $this->assertSame([13, 5, 5], [$p['total'], \count($p['items']), $p['limit']]);
    }

    // data-client AC-11 — A page past the end is empty, with the true total
    #[Group('spec:data-client:AC-11')]
    #[Group('spec:data-client')]
    public function testAPagePastTheEndIsEmptyWithTheTrueTotal(): void
    {
        $e = self::channels();
        $overflow = ['status' => 416, 'body' => '{"code":"PGRST103","details":"An offset of 100 was requested, but there are only 7 rows.","message":"Requested range not satisfiable"}'];
        $this->assertSame(['items' => [], 'total' => 7, 'limit' => 25, 'offset' => 100], self::db($overflow)->page($e, ['limit' => 25, 'offset' => 100]));
        $this->assertSame([], self::db($overflow)->list($e, ['limit' => 25, 'offset' => 100]));
    }

    // runtime-adapter AC-1 — The runtime adapter uses the call's own identity
    #[Group('spec:runtime-adapter:AC-1')]
    #[Group('spec:runtime-adapter')]
    public function testTheRuntimeAdapterUsesTheCallsOwnIdentity(): void
    {
        $seen = [];
        $context = new FakeContext('GET', '/', ['x-revenexx-context' => 'call-token', 'x-revenexx-tenant' => 'acme-eu', 'x-revenexx-market' => 'de']);
        $db = new Client(AdapterRegistry::create('runtime', ['context' => $context, 'env' => [], 'transport' => static function (string $m, string $url, array $headers) use (&$seen): array {
            $seen = $headers;

            return ['status' => 200, 'body' => '[]'];
        }]), ['channels' => self::channels()]);
        $db->list(self::channels());

        $this->assertSame('runtime', $db->adapter()->kind());
        $this->assertContains('Authorization: Bearer call-token', $seen);
        $this->assertContains('X-Revenexx-Tenant: acme-eu', $seen);
        $this->assertContains('X-Revenexx-Market: de', $seen);
    }

    // runtime-adapter AC-2 — The data plane is found without configuration
    #[Group('spec:runtime-adapter:AC-2')]
    #[Group('spec:runtime-adapter')]
    public function testTheDataPlaneIsFoundWithoutConfiguration(): void
    {
        $urls = [];
        $transport = static function (string $m, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['status' => 200, 'body' => '[]'];
        };
        $ctx = new FakeContext('GET', '/', ['x-revenexx-context' => 't']);
        RuntimeAdapter::create(['context' => $ctx, 'env' => [], 'transport' => $transport])->list(self::channels());
        RuntimeAdapter::create(['context' => $ctx, 'env' => ['REVENEXX_DATA_ENDPOINT' => 'https://data.dev.revenexx.test'], 'transport' => $transport])->list(self::channels());

        $this->assertStringStartsWith(RuntimeAdapter::DEFAULT_DATA_ENDPOINT . '/', $urls[0]);
        $this->assertStringStartsWith('https://data.dev.revenexx.test/', $urls[1]);
    }

    // runtime-adapter AC-5 — The record-history envelope travels with every data call
    #[Group('spec:runtime-adapter:AC-5')]
    #[Group('spec:runtime-adapter')]
    public function testTheRecordHistoryEnvelopeTravelsWithEveryDataCall(): void
    {
        $envelope = 'v1.k1.eyJ0ZW4iOiJhY21lIn0.c2ln-_x';
        $headers = [
            'x-revenexx-context' => 'call-token',
            'x-revenexx-tenant' => 'acme',
            'x-revenexx-market' => 'de',
            'x-revenexx-audit' => $envelope,
            'x-revenexx-contact' => 'c1',
            'x-revenexx-actor-trust' => 'asserted',
        ];
        $seen = [];
        $transport = static function (string $m, string $url, array $h) use (&$seen): array {
            $seen[] = $h;

            return ['status' => 200, 'body' => '[{"id":"r1","code":"web"}]'];
        };
        $db = new Client(AdapterRegistry::create('runtime', ['context' => new FakeContext('GET', '/', $headers), 'env' => [], 'transport' => $transport]), ['channels' => self::channels()]);
        $db->list(self::channels());
        $db->create(self::channels(), ['code' => 'web']);
        $db->update(self::channels(), 'r1', ['code' => 'pos']);
        $db->remove(self::channels(), 'r1');

        $this->assertCount(4, $seen);
        foreach ($seen as $h) {
            $this->assertContains('X-Revenexx-Audit: ' . $envelope, $h);
            $this->assertContains('Authorization: Bearer call-token', $h);
            $this->assertContains('X-Revenexx-Market: de', $h);
        }

        // The acting person is read from the same headers exactly as before.
        $actor = \Revenexx\AppSdk\Router\Actor::fromHeaders(static fn (string $n): string => $headers[strtolower($n)] ?? '');
        $this->assertSame(['c1', 'asserted', 'contact', null], [$actor->contact, $actor->trust, $actor->plane, $actor->user]);
    }

    // runtime-adapter AC-5 — The record-history envelope travels with every data call
    #[Group('spec:runtime-adapter:AC-5')]
    #[Group('spec:runtime-adapter')]
    public function testNoAuditHeaderWithoutAUsableEnvelope(): void
    {
        $seen = [];
        $transport = static function (string $m, string $url, array $h) use (&$seen): array {
            $seen[] = $h;

            return ['status' => 200, 'body' => '[]'];
        };
        foreach ([null, '', 'v1.k1.a b.c', "v1.k1.a\r\nX-Evil: 1", 'v1.ü.a.b', str_repeat('x', 4097)] as $audit) {
            $headers = ['x-revenexx-context' => 't', 'x-revenexx-tenant' => 'acme'];
            if ($audit !== null) {
                $headers['x-revenexx-audit'] = $audit;
            }
            RuntimeAdapter::create(['context' => new FakeContext('GET', '/', $headers), 'env' => ['REVENEXX_AUDIT' => 'v1.k.p.s'], 'transport' => $transport])->list(self::channels());
        }

        $this->assertCount(6, $seen);
        foreach ($seen as $h) {
            $this->assertEmpty(array_filter($h, static fn (string $line): bool => str_starts_with(strtolower($line), 'x-revenexx-audit')));
        }
    }

    // runtime-adapter AC-6 — A note on the change travels with every data call
    #[Group('spec:runtime-adapter:AC-6')]
    #[Group('spec:runtime-adapter')]
    public function testTheRecordHistoryNoteTravelsWithEveryDataCall(): void
    {
        $note = rawurlencode('{"title":"Zurückgesetzt auf Eintrag 4","reason":"Versehentlich geändert"}');
        $headers = [
            'x-revenexx-context' => 'call-token',
            'x-revenexx-tenant' => 'acme',
            'x-revenexx-audit' => 'v1.k1.eyJ0ZW4iOiJhY21lIn0.c2ln-_x',
            'x-revenexx-audit-note' => $note,
            'x-revenexx-contact' => 'c1',
            'x-revenexx-actor-trust' => 'asserted',
        ];
        $seen = [];
        $transport = static function (string $m, string $url, array $h) use (&$seen): array {
            $seen[] = $h;

            return ['status' => 200, 'body' => '[{"id":"r1","code":"web"}]'];
        };
        $db = new Client(AdapterRegistry::create('runtime', ['context' => new FakeContext('GET', '/', $headers), 'env' => [], 'transport' => $transport]), ['channels' => self::channels()]);
        $db->list(self::channels());
        $db->create(self::channels(), ['code' => 'web']);
        $db->update(self::channels(), 'r1', ['code' => 'pos']);
        $db->remove(self::channels(), 'r1');

        $this->assertCount(4, $seen);
        foreach ($seen as $h) {
            $this->assertContains('X-Revenexx-Audit-Note: ' . $note, $h);
            $this->assertContains('X-Revenexx-Audit: ' . $headers['x-revenexx-audit'], $h);
        }

        // The note names nobody: the acting person reads exactly as before.
        $actor = \Revenexx\AppSdk\Router\Actor::fromHeaders(static fn (string $n): string => $headers[strtolower($n)] ?? '');
        $this->assertSame(['c1', 'asserted', 'contact', null], [$actor->contact, $actor->trust, $actor->plane, $actor->user]);
    }

    // runtime-adapter AC-6 — A note on the change travels with every data call
    #[Group('spec:runtime-adapter:AC-6')]
    #[Group('spec:runtime-adapter')]
    public function testNoNoteHeaderWithoutAUsableNote(): void
    {
        $seen = [];
        $transport = static function (string $m, string $url, array $h) use (&$seen): array {
            $seen[] = $h;

            return ['status' => 200, 'body' => '[]'];
        };
        $bad = [null, '', "a\r\nX-Evil: 1", "tab\there", '{"title":"ü"}', str_repeat('x', 8193)];
        foreach ($bad as $value) {
            $headers = ['x-revenexx-context' => 't', 'x-revenexx-tenant' => 'acme'];
            if ($value !== null) {
                $headers['x-revenexx-audit-note'] = $value;
            }
            RuntimeAdapter::create(['context' => new FakeContext('GET', '/', $headers), 'env' => ['REVENEXX_AUDIT_NOTE' => 'x'], 'transport' => $transport])->list(self::channels());
        }

        $this->assertCount(\count($bad), $seen);
        foreach ($seen as $h) {
            $this->assertEmpty(array_filter($h, static fn (string $line): bool => str_starts_with(strtolower($line), 'x-revenexx-audit-note')));
        }

        // Exactly at the cap still travels.
        $atCap = str_repeat('x', 8192);
        RuntimeAdapter::create(['context' => new FakeContext('GET', '/', ['x-revenexx-audit-note' => $atCap]), 'env' => [], 'transport' => $transport])->list(self::channels());
        $this->assertContains('X-Revenexx-Audit-Note: ' . $atCap, end($seen));
    }

    // runtime-adapter AC-6 — A note on the change travels with every data call
    #[Group('spec:runtime-adapter:AC-6')]
    #[Group('spec:runtime-adapter')]
    public function testTheAppMayDescribeItsOwnChange(): void
    {
        $seen = [];
        $transport = static function (string $m, string $url, array $h) use (&$seen): array {
            $seen[] = $h;

            return ['status' => 201, 'body' => '[{"id":"r1","code":"web"}]'];
        };
        $own = rawurlencode('{"title":"Preisliste importiert"}');
        $context = new FakeContext('GET', '/', ['x-revenexx-context' => 't', 'x-revenexx-audit-note' => 'from-request']);
        RuntimeAdapter::create(['context' => $context, 'env' => [], 'transport' => $transport, 'auditNote' => $own])->create(self::channels(), ['code' => 'web']);
        RuntimeAdapter::create(['context' => $context, 'env' => [], 'transport' => $transport, 'auditNote' => ''])->create(self::channels(), ['code' => 'web']);

        $this->assertContains('X-Revenexx-Audit-Note: ' . $own, $seen[0]);
        $this->assertEmpty(array_filter($seen[1], static fn (string $line): bool => str_starts_with(strtolower($line), 'x-revenexx-audit-note')));
    }
}
