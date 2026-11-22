<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Query;
use Revenexx\AppSdk\Refusal;
use Revenexx\AppSdk\Tenant;
use Revenexx\AppSdk\Tests\Support\PlatformException;
use Revenexx\AppSdk\Router\Actor;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Router\RouteContext;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * JS ↔ PHP parity. The PHP and the JavaScript SDK answer the same shared cases
 * (a JSON fixture kept beside the JavaScript SDK). A case belongs in the fixture,
 * not in one runtime's test.
 */
final class ParityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../fixtures/parity.json';

    /**
     * The shared cases. They live beside the JavaScript SDK, so a checkout of this
     * package alone has none, and these tests skip rather than fail.
     *
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        if (! is_file(self::FIXTURE)) {
            return [];
        }

        return json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function requireFixture(): void
    {
        if (! is_file(self::FIXTURE)) {
            self::markTestSkipped('the shared parity cases are not part of this package');
        }
    }

    /**
     * One placeholder data set when the fixture is absent, so a data provider is
     * never empty (PHPUnit refuses that) and the test can skip.
     *
     * @return iterable<string, list<mixed>>
     */
    private static function absent(int $arity): iterable
    {
        yield 'parity cases not available' => array_fill(0, $arity, []);
    }

    /** @param list<string> $ops */
    private static function things(array $ops = ['list', 'get']): Entity
    {
        return new Entity(
            name: 'things',
            table: 't_things',
            pk: 'id',
            columns: ['id' => ['type' => 'text', 'pk' => true], 'status' => ['type' => 'text'], 'n' => ['type' => 'int'], 'name' => ['type' => 'text'], 'tag' => ['type' => 'text']],
            ops: $ops,
        );
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, string}>}> */
    public static function queries(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(2);

            return;
        }
        foreach (self::fixture()['queries'] as $case) {
            yield $case['name'] => [$case['query'], $case['params']];
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function matchCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(2);

            return;
        }
        foreach (self::fixture()['matches'] as $case) {
            yield $case['name'] => [$case['where'], $case['ids']];
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function listValueCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(2);

            return;
        }
        foreach (self::fixture()['listValues']['matches'] as $case) {
            yield $case['name'] => [$case['where'], $case['ids']];
        }
    }

    /**
     * @param array<string, mixed>         $query
     * @param list<array{string, string}> $params
     */
    // runtime-parity AC-1 — A query serialises to the same parameters in both runtimes
    #[Group('spec:runtime-parity:AC-1')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('queries')]
    public function testSerialisesToTheFixturesParameters(array $query, array $params): void
    {
        self::requireFixture();
        $pairs = array_map(
            static fn (string $pair): array => array_map('rawurldecode', explode('=', $pair, 2)),
            explode('&', Query::toParams($query)),
        );

        self::assertSame($params, $pairs);
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>         $ids
     */
    // runtime-parity AC-2 — A query matches the same rows in both mocks
    #[Group('spec:runtime-parity:AC-2')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('matchCases')]
    public function testTheMockMatchesTheFixturesRows(array $where, array $ids): void
    {
        self::requireFixture();
        $e = self::things();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['things' => self::fixture()['rows']]]), [$e->name => $e]);

        self::assertSame($ids, array_column($db->list($e, ['where' => $where, 'order' => 'id.asc']), 'id'));
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>         $ids
     */
    // runtime-parity AC-2 — A query matches the same rows in both mocks
    #[Group('spec:runtime-parity:AC-2')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('listValueCases')]
    public function testTheMockMatchesTheFixturesListValueRows(array $where, array $ids): void
    {
        self::requireFixture();
        $e = self::things();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['things' => self::fixture()['listValues']['rows']]]), [$e->name => $e]);

        self::assertSame($ids, array_column($db->list($e, ['where' => $where, 'order' => 'id.asc']), 'id'));
    }

    // runtime-parity AC-3 — A refusal reads the same in both runtimes
    #[Group('spec:runtime-parity:AC-3')]
    #[Group('spec:runtime-parity')]
    public function testRefusalsUseTheFixturesWording(): void
    {
        self::requireFixture();
        $messages = self::fixture()['messages'];
        $e = self::things(['list', 'get', 'update']);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['things' => self::fixture()['rows']]]), [$e->name => $e]);

        foreach ([
            $messages['emptyUpdate'] => static fn () => $db->update($e, '1', []),
            $messages['emptyCondition'] => static fn () => $db->updateIf($e, '1', [], ['n' => 2]),
            $messages['notPermitted'] => static fn () => $db->create($e, []),
        ] as $message => $call) {
            try {
                $call();
                self::fail("expected: {$message}");
            } catch (\RuntimeException|\InvalidArgumentException $ex) {
                self::assertSame($message, $ex->getMessage());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function errorCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['errors'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-4 — A data-plane refusal maps to the same answer in both runtimes
    #[Group('spec:runtime-parity:AC-4')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('errorCases')]
    public function testMapsADataPlaneRefusalToTheFixturesAnswer(array $case): void
    {
        self::requireFixture();
        $e = self::things(['list', 'get', 'create', 'delete']);
        $adapter = new RemoteAdapter('http://pgrst:3000', transport: static fn (): array => ['status' => $case['status'], 'body' => $case['body']]);
        $db = new Client($adapter, [$e->name => $e]);
        $run = match ($case['call']) {
            'create' => static fn () => $db->create($e, ['name' => 'x']),
            'delete' => static fn () => $db->remove($e, '1'),
            'get' => static fn () => $db->get($e, '1'),
            default => static fn () => $db->list($e),
        };

        try {
            $run();
            self::fail('expected a refusal');
        } catch (HttpError $err) {
            self::assertSame($case['expect'], ['status' => $err->status, 'code' => $err->errorCode, 'message' => $err->getMessage()]);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function listFilterCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['listFilters']['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-5 — A mounted list answers the same querystring the same way in both runtimes
    #[Group('spec:runtime-parity:AC-5')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('listFilterCases')]
    public function testTheMountedListAnswersTheFixturesQuerystring(array $case): void
    {
        self::requireFixture();
        $lf = self::fixture()['listFilters'];
        $e = new Entity('markets', 't_markets', 'id', $lf['columns'], ['list', 'get', 'create', 'update', 'delete']);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['markets' => $lf['rows']]]), [$e->name => $e]);
        $app = new App(['name' => 'markets']);
        Crud::mount($app, $db, $e, ['path' => '/markets', 'queryParams' => $lf['queryParams']]);

        $res = FakeContext::call($app, 'GET', '/markets', ['query' => $case['query']]);

        self::assertSame($case['status'], $res['status'], $res['raw']);
        if (isset($case['code'])) {
            self::assertSame($case['code'], $res['data']['code']);
        }
        if (isset($case['total'])) {
            self::assertSame($case['total'], $res['data']['page']['total']);
        }
        if (isset($case['filter'])) {
            self::assertSame($case['filter'], $res['data']['filter']);
            // An empty filter is an object on the wire, as in JavaScript — never a list.
            self::assertStringContainsString('"filter":{', $res['raw']);
        }
        // An unknown filter names only the offending keys, never the accepted ones.
        if (($case['code'] ?? null) === 'unknown_filter') {
            self::assertSame(['unknown' => $case['unknown']], $res['data']['details']);
        } elseif (isset($case['unknown'])) {
            self::assertSame($case['unknown'], $res['data']['details']['unknown']);
        }
        if (isset($case['parameter'])) {
            self::assertSame($case['parameter'], $res['data']['details']['parameter']);
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, mixed>}> */
    public static function actorCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(2);

            return;
        }
        foreach (self::fixture()['actor'] as $case) {
            yield $case['name'] => [$case['headers'], $case['expect']];
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $expect
     */
    // runtime-parity AC-6 — The same headers read as the same acting person in both runtimes
    #[Group('spec:runtime-parity:AC-6')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('actorCases')]
    public function testReadsTheFixturesActingPerson(array $headers, array $expect): void
    {
        self::requireFixture();
        $a = Actor::fromHeaders(static fn (string $n): ?string => $headers[$n] ?? null);

        self::assertSame($expect, [
            'user' => $a->user,
            'email' => $a->email,
            'contact' => $a->contact,
            'organization' => $a->organization,
            'rep' => $a->rep,
            'permissions' => $a->permissions,
            'trust' => $a->trust,
            'plane' => $a->plane,
            'authenticated' => $a->authenticated(),
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function routingCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['routing'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-7 — The router answers its own refusals the same way in both runtimes
    #[Group('spec:runtime-parity:AC-7')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('routingCases')]
    public function testTheRouterAnswersTheFixturesRefusals(array $case): void
    {
        self::requireFixture();
        $app = new App(['name' => 'parity']);
        $app->get('/things', static fn (RouteContext $c) => $c->json([]));
        $app->get('/boom', static fn () => throw new \RuntimeException('kaput — internals'));
        $app->get('/missing', static fn () => throw HttpError::notFound('gone'));

        $res = FakeContext::call($app, $case['method'], $case['path']);

        self::assertSame($case['status'], $res['status']);
        self::assertSame($case['code'], $res['data']['code']);
        if (isset($case['error'])) {
            self::assertSame($case['error'], $res['data']['error']);
        }
        // The names the platform client reads.
        self::assertSame([$res['data']['error'], $res['data']['code']], [$res['data']['message'], $res['data']['type']]);
    }

    /** @return iterable<string, array{array<string, mixed>, ?array<string, mixed>}> */
    public static function refusalCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(2);

            return;
        }
        foreach (self::fixture()['refusals'] as $case) {
            yield $case['name'] => [$case['thrown'], $case['expect']];
        }
    }

    /**
     * @param array<string, mixed>      $thrown
     * @param array<string, mixed>|null $expect
     */
    // runtime-parity AC-8 — A refusal is read the same way in both runtimes
    #[Group('spec:runtime-parity:AC-8')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('refusalCases')]
    public function testReadsTheFixturesRefusal(array $thrown, ?array $expect): void
    {
        self::requireFixture();
        $r = Refusal::of(PlatformException::thrownFrom($thrown));

        self::assertSame($expect, $r === null ? null : [
            'status' => $r->status,
            'code' => $r->code,
            'message' => $r->message,
            'details' => $r->details,
            'app' => $r->app,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function writeCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['writes']['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, mixed>|null
     */
    private static function writeThrough(Client $db, Entity $e, array $case): ?array
    {
        return match ($case['call']) {
            'create' => $db->create($e, $case['data']),
            'update' => $db->update($e, $case['id'], $case['data']),
            default => $db->updateIf($e, $case['id'], $case['where'], $case['data']),
        };
    }

    /** @param array<string, mixed> $case */
    private function assertWriteRefusal(HttpError $err, array $case): void
    {
        $this->assertSame([$case['status'], $case['code']], [$err->status, $err->errorCode]);
        // Only an unknown field names keys; a list is refused as a whole.
        $this->assertSame(
            match (true) {
                $case['code'] === 'unknown_field' => ['unknown' => $case['unknown']],
                isset($case['field']) => ['field' => $case['field'], 'fields' => [$case['field']]],
                default => null,
            },
            $err->details,
        );
        if (isset($case['message'])) {
            $this->assertSame($case['message'], $err->getMessage());
        }
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-9 — A write is checked against the same columns in both runtimes
    // data-client AC-16 — A write is one record, never a list
    // crud-routes AC-10 — A create or update naming a field the resource does not have is a 400
    // data-client AC-17 — A create with no field is refused before any request
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:data-client:AC-16')]
    #[Group('spec:data-client')]
    #[Group('spec:crud-routes:AC-10')]
    #[Group('spec:crud-routes')]
    #[Group('spec:data-client:AC-17')]
    #[DataProvider('writeCases')]
    public function testAWriteIsCheckedAgainstTheFixturesColumns(array $case): void
    {
        self::requireFixture();
        $w = self::fixture()['writes'];
        $e = new Entity('accounts', 't_accounts', 'id', $w['columns'], ['list', 'get', 'create', 'update']);

        // The client on the mock: refused writes change nothing. It answers
        // `mock` where the fixture says the mock differs.
        $onMock = [...$case, ...($case['mock'] ?? [])];
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['accounts' => $w['rows']]]), ['accounts' => $e]);
        try {
            $row = self::writeThrough($db, $e, $case);
            $this->assertLessThan(400, $onMock['status'], 'expected a refusal');
            foreach ($case['data'] as $k => $v) {
                $this->assertSame($v, $row[$k]);
            }
        } catch (HttpError $err) {
            $this->assertWriteRefusal($err, $onMock);
            $this->assertSame($w['rows'], $db->list($e));
        }

        // The remote adapter: a refused write sends no request.
        $sent = [];
        $remote = new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use (&$sent, $case): array {
            $sent[] = json_decode((string) $body, true);

            return ['status' => 200, 'body' => (string) json_encode([['id' => $case['id'] ?? 'x', ...$case['data']]])];
        });
        $db = new Client($remote, ['accounts' => $e]);
        try {
            self::writeThrough($db, $e, $case);
            $this->assertLessThan(400, $case['status'], 'expected a refusal');
            $this->assertSame([$case['data']], $sent);
        } catch (HttpError $err) {
            $this->assertWriteRefusal($err, $case);
            $this->assertSame([], $sent);
        }

        // The mounted collection, for the two writes it routes.
        if ($case['call'] === 'updateIf') {
            return;
        }
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['accounts' => $w['rows']]]), ['accounts' => $e]);
        $app = new App(['name' => 'accounts']);
        Crud::mount($app, $db, $e, ['path' => '/accounts']);
        $res = $case['call'] === 'create'
            ? FakeContext::call($app, 'POST', '/accounts', ['body' => $case['data']])
            : FakeContext::call($app, 'PUT', "/accounts/{$case['id']}", ['body' => $case['data']]);
        $this->assertSame($case['status'], $res['status'], $res['raw']);
        if (isset($case['code'])) {
            $this->assertSame($case['code'], $res['data']['code']);
        }
        if (isset($case['unknown'])) {
            $this->assertSame($case['unknown'], $res['data']['details']['unknown']);
        }
        if (isset($case['message'])) {
            $this->assertSame($case['message'], $res['data']['message']);
        }
        if ($case['status'] >= 400) {
            // A refused write changed nothing.
            $this->assertSame($w['rows'], $db->list($e));
        }
        if ($case['call'] !== 'create') {
            return;
        }

        // A hand-written handler that creates from the body, handed the raw JSON text.
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['accounts' => $w['rows']]]), ['accounts' => $e]);
        $app = new App(['name' => 'accounts']);
        $app->post('/import', static fn ($c) => $c->json($db->create($e, $c->body), 201));
        $res = FakeContext::call($app, 'POST', '/import', ['body' => self::rawJson($case['data'])]);
        $this->assertSame($onMock['status'], $res['status'], $res['raw']);
        if (isset($onMock['code'])) {
            $this->assertSame($onMock['code'], $res['data']['code']);
        }
        if (isset($onMock['message'])) {
            $this->assertSame($onMock['message'], $res['data']['message']);
        }
        if ($onMock['status'] >= 400) {
            $this->assertSame($w['rows'], $db->list($e));
        }
    }

    /**
     * The fixture's JSON for a body. PHP decoded `{"0":…}` and `[…]` alike, so
     * the text is re-encoded; both decode back to the array the runtime sees.
     */
    private static function rawJson(mixed $data): string
    {
        return (string) json_encode($data);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function flagWriteCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['flag_writes']['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return array<string, Entity> */
    private static function flagEntities(): array
    {
        $out = [];
        foreach (self::fixture()['flag_writes']['entities'] as $name => $e) {
            $out[$name] = new Entity(
                $name,
                "t_{$name}",
                'id',
                $e['columns'],
                ['list', 'get', 'create', 'update'],
                trash: $e['trash'] ?? false,
                externalId: $e['external_id'] ?? false,
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-9 — A write is checked against the same columns in both runtimes
    // crud-routes AC-11 — A body to a mounted trash entity cannot set the trash columns
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:crud-routes:AC-11')]
    #[Group('spec:crud-routes')]
    #[DataProvider('flagWriteCases')]
    public function testTheColumnsAFlagAddsAreCheckedAsTheFixtureSays(array $case): void
    {
        self::requireFixture();
        $f = self::fixture()['flag_writes'];
        $entities = self::flagEntities();
        $e = $entities[$case['entity']];
        $refused = function (HttpError $err) use ($case, $f): void {
            $this->assertSame([$case['status'], $case['code']], [$err->status, $err->errorCode]);
            $this->assertSame(['unknown' => $case['unknown']], $err->details);
            if (isset($case['message'])) {
                $this->assertSame($case['message'], $err->getMessage());
            }
        };

        $seed = array_map(static fn (): array => $f['rows'], $entities);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => $seed]), $entities);
        try {
            $row = self::writeThrough($db, $e, $case);
            $this->assertLessThan(400, $case['status'], 'expected a refusal');
            foreach ($case['data'] as $k => $v) {
                $this->assertSame($v, $row[$k]);
            }
        } catch (HttpError $err) {
            $refused($err);
            $this->assertSame($f['rows'], $db->list($e));
        }

        $sent = [];
        $remote = new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use (&$sent, $case): array {
            $sent[] = json_decode((string) $body, true);

            return ['status' => 200, 'body' => (string) json_encode([['id' => $case['id'] ?? 'x', ...$case['data']]])];
        });
        $db = new Client($remote, $entities);
        try {
            self::writeThrough($db, $e, $case);
            $this->assertLessThan(400, $case['status'], 'expected a refusal');
            $this->assertSame([$case['data']], $sent);
        } catch (HttpError $err) {
            $refused($err);
            $this->assertSame([], $sent);
        }

        // The mounted collection, for the two writes it routes.
        if ($case['call'] === 'updateIf') {
            return;
        }
        $db = new Client(AdapterRegistry::create('mock', ['seed' => $seed]), $entities);
        $app = new App(['name' => $case['entity']]);
        Crud::mount($app, $db, $e, ['path' => "/{$case['entity']}"]);
        $res = $case['call'] === 'create'
            ? FakeContext::call($app, 'POST', "/{$case['entity']}", ['body' => $case['data']])
            : FakeContext::call($app, 'PUT', "/{$case['entity']}/{$case['id']}", ['body' => $case['data']]);
        $want = $case['route'] ?? ['status' => $case['status'], 'code' => $case['code'] ?? null];
        $this->assertSame($want['status'], $res['status'], $res['raw']);
        if ($want['status'] >= 400) {
            $this->assertSame($want['code'], $res['data']['code']);
            $this->assertSame(
                isset($case['route']) ? ['fields' => $case['route']['fields']] : ['unknown' => $case['unknown']],
                $res['data']['details'],
            );
            $this->assertSame($f['rows'], $db->list($e));
        } elseif ($want['unchanged'] ?? false) {
            // Nothing left to write: the record as it stands, untouched.
            $stored = array_values(array_filter($f['rows'], static fn (array $r): bool => $r['id'] === $case['id']))[0];
            $this->assertSame($stored, $res['data']);
            $this->assertSame($f['rows'], $db->list($e));
        } else {
            foreach ($case['data'] as $k => $v) {
                $this->assertSame($v, $res['data'][$k]);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function flagFilterCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['flag_writes']['filters'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // runtime-parity AC-5 — A mounted list answers the same querystring the same way in both runtimes
    // list-filters AC-9 — A column the entity's trash or external_id flag adds filters a list like a declared one
    #[Group('spec:runtime-parity:AC-5')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:list-filters:AC-9')]
    #[Group('spec:list-filters')]
    #[DataProvider('flagFilterCases')]
    public function testAColumnAFlagAddsFiltersAMountedListAsTheFixtureSays(array $case): void
    {
        self::requireFixture();
        $f = self::fixture()['flag_writes'];
        $entities = self::flagEntities();
        $e = $entities[$case['entity']];
        $db = new Client(AdapterRegistry::create('mock', ['seed' => [$case['entity'] => $f['filter_rows']]]), $entities);
        $app = new App(['name' => $case['entity']]);
        Crud::mount($app, $db, $e, array_filter([
            'path' => "/{$case['entity']}",
            'columns' => $case['columns'] ?? null,
            'queryParams' => $case['queryParams'] ?? null,
        ], static fn (mixed $v): bool => $v !== null));
        $res = FakeContext::call($app, 'GET', "/{$case['entity']}", ['query' => $case['query']]);

        $this->assertSame($case['status'], $res['status'], $res['raw']);
        if (isset($case['code'])) {
            $this->assertSame($case['code'], $res['data']['code']);
        }
        if (isset($case['total'])) {
            $this->assertSame($case['total'], $res['data']['page']['total']);
        }
        if (isset($case['filter'])) {
            $this->assertSame($case['filter'], $res['data']['filter']);
        }
        // An unknown filter names only the offending keys, never the columns a flag adds.
        if (($case['code'] ?? null) === 'unknown_filter') {
            $this->assertSame(['unknown' => $case['unknown']], $res['data']['details']);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function bulkCases(): iterable
    {
        if (self::fixture() === []) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['bulk_creates']['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // data-client AC-18 — createMany inserts a list of records in one request
    // runtime-parity AC-9 — A write is checked against the same columns in both runtimes
    #[Group('spec:data-client:AC-18')]
    #[Group('spec:data-client')]
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('bulkCases')]
    public function testABulkCreateIsCheckedAlikeAndSentOnce(array $case): void
    {
        self::requireFixture();
        $b = self::fixture()['bulk_creates'];
        $e = new Entity('accounts', 't_accounts', 'id', $b['columns'], ['list', 'get', 'create']);
        $refused = function (HttpError $err, array $want) use ($case, $b): void {
            $this->assertSame([$want['status'], $want['code']], [$err->status, $err->errorCode]);
            if ($want !== $case) {
                return;
            }
            $at = isset($case['index']) ? ['index' => $case['index']] : [];
            $this->assertSame(
                $case['code'] === 'unknown_field' ? [...$at, 'unknown' => $case['unknown']] : ($at !== [] ? $at : null),
                $err->details,
            );
            if (isset($case['message'])) {
                $this->assertSame($case['message'], $err->getMessage());
            }
        };
        $records = $case['rows'] ?? (\is_array($case['data']) ? $case['data'] : []);

        // The client on the mock: a refusal stores nothing.
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['accounts' => $b['rows']]]), ['accounts' => $e]);
        $want = $case['mock'] ?? $case;
        try {
            $rows = $db->createMany($e, \is_array($case['data']) ? $case['data'] : []);
            $this->assertLessThan(400, $want['status'], 'expected a refusal');
            foreach ($records as $i => $record) {
                foreach ($record as $k => $v) {
                    $this->assertSame($v, $rows[$i][$k]);
                }
            }
            $this->assertCount(\count($b['rows']) + \count($records), $db->list($e));
        } catch (HttpError $err) {
            $refused($err, $want);
            $this->assertSame($b['rows'], $db->list($e));
        }

        // The remote adapter: one request, the records in index order; a refusal sends none.
        $sent = [];
        $remote = new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use (&$sent): array {
            $sent[] = json_decode((string) $body, true);

            return ['status' => 201, 'body' => (string) $body];
        });
        $db = new Client($remote, ['accounts' => $e]);
        try {
            $rows = $db->createMany($e, \is_array($case['data']) ? $case['data'] : []);
            $this->assertLessThan(400, $case['status'], 'expected a refusal');
            $this->assertSame([$records], $sent);
            $this->assertSame($records, $rows);
        } catch (HttpError $err) {
            $refused($err, $case);
            $this->assertSame([], $sent);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function roundTripCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield 'parity cases not available' => [[], 'client'];

            return;
        }
        foreach (self::fixture()['round_trips']['cases'] as $case) {
            foreach (['client', 'route'] as $layer) {
                if (isset($case[$layer])) {
                    yield "{$layer}: {$case['name']}" => [$case, $layer];
                }
            }
        }
    }

    private static function roundTripEntity(): Entity
    {
        $r = self::fixture()['round_trips'];

        return new Entity('items', 't_items', $r['entity']['pk'], $r['entity']['columns'], ['list', 'get', 'create', 'update'], trash: $r['entity']['trash']);
    }

    /**
     * A data plane holding the fixture's row: a read by id or by filter answers
     * it when every `eq.` filter matches, a PATCH and a POST the row they made.
     *
     * @param list<array<string, mixed>> $writes
     */
    private static function roundTripPlane(array &$writes, array $row): RemoteAdapter
    {
        $r = self::fixture()['round_trips'];

        return new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use (&$writes, $r, $row): array {
            if ($m === 'GET') {
                $holds = true;
                foreach (explode('&', (string) parse_url($u, PHP_URL_QUERY)) as $pair) {
                    [$k, $v] = array_map('rawurldecode', explode('=', $pair, 2) + [1 => '']);
                    if ($k === '' || \in_array($k, ['select', 'limit', 'offset', 'order'], true)) {
                        continue;
                    }
                    $holds = $holds && str_starts_with($v, 'eq.') && (string) ($row[$k] ?? '') === substr($v, 3);
                }

                return ['status' => 200, 'body' => (string) json_encode($holds ? [$row] : [])];
            }
            $data = json_decode((string) $body, true);
            $writes[] = $data;
            $made = $m === 'POST' ? ['id' => 'new', 'tenant_id' => $r['tenant'], ...$data] : [...$row, ...$data];

            return ['status' => $m === 'POST' ? 201 : 200, 'body' => (string) json_encode([$made])];
        });
    }

    /**
     * A record read and sent back, through the data client and the mounted
     * route, on a data plane that records every write.
     *
     * @param array<string, mixed> $case
     */
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:data-client:AC-19')]
    #[Group('spec:data-client')]
    #[Group('spec:crud-routes:AC-12')]
    #[Group('spec:crud-routes:AC-13')]
    #[Group('spec:crud-routes')]
    #[DataProvider('roundTripCases')]
    public function testARecordSentBackIsAnsweredAsTheFixtureSays(array $case, string $layer): void
    {
        self::requireFixture();
        $r = self::fixture()['round_trips'];
        $e = self::roundTripEntity();
        $writes = [];
        $stored = [...$r['row'], ...($case['stored'] ?? [])];
        $db = new Client(self::roundTripPlane($writes, $stored), ['items' => $e]);
        $want = $case[$layer];

        if ($layer === 'client') {
            try {
                $row = $case['call'] === 'updateIf'
                    ? $db->updateIf($e, $case['id'], $case['where'], $case['data'])
                    : $db->update($e, $case['id'], $case['data']);
                $this->assertLessThan(400, $want['status'], 'expected a refusal');
            } catch (HttpError $err) {
                $this->assertSame([$want['status'], $want['code']], [$err->status, $err->errorCode]);
                if (isset($want['fields'])) {
                    $this->assertSame($want['fields'], $err->details['fields'] ?? null);
                }
                $this->assertSame([], $writes);

                return;
            }
        } else {
            $app = new App(['name' => 'items']);
            $parent = $case['parent'] ?? null;
            $base = $parent !== null ? '/parents/' . $stored[$parent] . '/items' : '/items';
            Crud::mount($app, $db, $e, [
                'path' => $parent !== null ? '/parents/{parentId}/items' : '/items',
                'columns' => $r['entity']['columns'],
                'readOnly' => $r['readOnly'],
                ...($parent !== null ? ['parent' => ['param' => 'parentId', 'column' => $parent]] : []),
            ]);
            $res = $case['call'] === 'create'
                ? FakeContext::call($app, 'POST', $base, ['body' => $case['data'], 'headers' => ['x-revenexx-tenant' => $r['tenant']]])
                : FakeContext::call($app, 'PUT', "{$base}/{$case['id']}", ['body' => $case['data'], 'headers' => ['x-revenexx-tenant' => $r['tenant']]]);
            $this->assertSame($want['status'], $res['status'], $res['raw']);
            if ($want['status'] >= 400) {
                $this->assertSame($want['code'], $res['data']['code']);
                if (isset($want['fields'])) {
                    $this->assertSame($want['fields'], $res['data']['details']['fields'] ?? null);
                }
                $this->assertSame([], $writes);

                return;
            }
            $row = $res['data'];
        }

        if (\array_key_exists('write', $want) && $want['write'] === null) {
            $this->assertSame([], $writes);
            $answer = \array_key_exists('answer', $want) ? $want['answer'] : 'row';
            // The client returns the row without `tenant_id` (data-client AC-21).
            $this->assertEquals($answer === 'row' ? Tenant::withoutTenantId($stored) : null, $row);
        } else {
            $this->assertEquals([$want['write']], $writes);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function mockGrantCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['round_trips']['mock_grant'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:mock-fidelity:AC-6')]
    #[Group('spec:mock-fidelity')]
    #[DataProvider('mockGrantCases')]
    public function testTheMockRefusesTheKeyAndTheTenantAsTheGrantDoes(array $case): void
    {
        self::requireFixture();
        $r = self::fixture()['round_trips'];
        $e = self::roundTripEntity();
        $mock = AdapterRegistry::create('mock', ['seed' => ['items' => [$r['row']]]]);
        foreach ([
            static fn () => $mock->update($e, 'r1', $case['data']),
            static fn () => $mock->updateIf($e, 'r1', ['code' => 'one'], $case['data']),
        ] as $write) {
            try {
                $write();
                $this->fail('expected the grant to refuse the write');
            } catch (HttpError $err) {
                $this->assertSame([401, 'bad_request', 'unauthenticated'], [$err->status, $err->errorCode, $err->getMessage()]);
                $this->assertSame(['42501', $case['columns']], [$err->serverDetail['sqlstate'] ?? null, $err->serverDetail['columns'] ?? null]);
            }
        }
        $this->assertSame($r['row'], $mock->get($e, 'r1'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function mockStampCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['round_trips']['mock_stamp'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[Group('spec:mock-fidelity:AC-1')]
    #[Group('spec:mock-fidelity')]
    #[DataProvider('mockStampCases')]
    public function testTheMockStampsUpdatedAtAsTheTriggerDoes(array $case): void
    {
        self::requireFixture();
        $r = self::fixture()['round_trips'];
        $mock = AdapterRegistry::create('mock', ['seed' => ['items' => [$r['row']]]]);
        $row = $mock->update(self::roundTripEntity(), 'r1', $case['data']);
        $this->assertSame($case['stamps'], $row['updated_at'] !== $r['row']['updated_at'] && $row['updated_at'] !== ($case['data']['updated_at'] ?? null));
    }
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function storeOwnedCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield 'parity cases not available' => [[], 'mock'];

            return;
        }
        foreach (self::fixture()['store_owned_writes']['cases'] as $case) {
            foreach (['mock', 'remote'] as $layer) {
                if (isset($case[$layer])) {
                    yield "{$layer}: {$case['name']}" => [$case, $layer];
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return array<array-key, mixed>|null
     */
    private static function storeOwnedWrite(Client $db, Entity $e, array $case): ?array
    {
        return match ($case['call']) {
            'create' => $db->create($e, $case['data']),
            'createMany' => $db->createMany($e, $case['data']),
            'update' => $db->update($e, $case['id'], $case['data']),
            default => $db->updateIf($e, $case['id'], $case['where'], $case['data']),
        };
    }

    /**
     * A write the data plane refuses for want of a grant names the field.
     *
     * @param array<string, mixed> $case
     */
    // data-client AC-20 — A write the data plane refuses for the tenant or a store-assigned key names the field
    // mock-fidelity AC-7 — A create naming the tenant or a store-assigned key fails as the grant makes it fail
    #[Group('spec:data-client:AC-20')]
    #[Group('spec:data-client')]
    #[Group('spec:mock-fidelity:AC-7')]
    #[Group('spec:mock-fidelity')]
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('storeOwnedCases')]
    public function testAWriteRefusedForAGrantNamesTheField(array $case, string $layer): void
    {
        self::requireFixture();
        $o = self::fixture()['store_owned_writes'];
        $def = $o['entities'][$case['entity']];
        $e = new Entity($case['entity'], 't_items', $def['pk'], $def['columns'], ['list', 'get', 'create', 'update']);
        $want = $case[$layer];
        $refused = function (HttpError $err) use ($want): void {
            $this->assertSame([$want['status'], $want['code']], [$err->status, $err->errorCode]);
            if (isset($want['message'])) {
                $this->assertSame($want['message'], $err->getMessage());
            }
            $this->assertSame(isset($want['field']) ? ['field' => $want['field'], 'fields' => [$want['field']]] : null, $err->details);
        };

        if ($layer === 'remote') {
            $plane = $case['plane'] ?? $o['denied'];
            $remote = new RemoteAdapter('http://pgrst:3000', transport: static fn (string $m, string $u, array $h, ?string $body): array => $m === 'GET'
                ? ['status' => 200, 'body' => (string) json_encode($o['rows'])]
                : ['status' => $plane['status'], 'body' => $plane['body']]);
            try {
                self::storeOwnedWrite(new Client($remote, [$case['entity'] => $e]), $e, $case);
                $this->fail('expected the data plane to refuse the write');
            } catch (HttpError $err) {
                $refused($err);
                // The diagnosis stays on the refusal, for the log.
                $this->assertSame(json_decode($plane['body'], true)['code'], $err->serverDetail['sqlstate'] ?? null);
            }

            return;
        }

        // The mock adapter itself, called past the client, refuses as the grant does.
        if (isset($case['adapter_columns'])) {
            $mock = AdapterRegistry::create('mock', ['seed' => [$case['entity'] => $o['rows']]]);
            try {
                $case['call'] === 'createMany' ? $mock->createMany($e, $case['data']) : $mock->create($e, $case['data']);
                $this->fail('expected the grant to refuse the write');
            } catch (HttpError $err) {
                $this->assertSame([401, 'bad_request', 'unauthenticated'], [$err->status, $err->errorCode, $err->getMessage()]);
                $this->assertSame(['42501', $case['adapter_columns']], [$err->serverDetail['sqlstate'] ?? null, $err->serverDetail['columns'] ?? null]);
            }
            $this->assertSame($o['rows'], $mock->list($e));
        }

        $db = new Client(AdapterRegistry::create('mock', ['seed' => [$case['entity'] => $o['rows']]]), [$case['entity'] => $e]);
        try {
            self::storeOwnedWrite($db, $e, $case);
            $this->assertLessThan(400, $want['status'], 'expected a refusal');
            $this->assertCount(\count($o['rows']) + ($case['call'] === 'createMany' ? \count($case['data']) : 1), $db->list($e));
        } catch (HttpError $err) {
            $refused($err);
            $this->assertSame(Tenant::withoutTenantId($o['rows']), $db->list($e));
        }
        if ($case['call'] !== 'create') {
            return;
        }

        // A hand-written handler that creates from the body answers the same.
        $db = new Client(AdapterRegistry::create('mock', ['seed' => [$case['entity'] => $o['rows']]]), [$case['entity'] => $e]);
        $app = new App(['name' => 'items']);
        $app->post('/import', static fn ($c) => $c->json($db->create($e, $c->body), 201));
        $res = FakeContext::call($app, 'POST', '/import', ['body' => self::rawJson($case['data'])]);
        $this->assertSame($want['status'], $res['status'], $res['raw']);
        if ($want['status'] >= 400) {
            $this->assertSame(
                [$want['code'], $want['message'], ['field' => $want['field'], 'fields' => [$want['field']]]],
                [$res['data']['code'], $res['data']['message'], $res['data']['details']],
            );
        }
    }

    /** @return array{Entity, array<string, mixed>} */
    private static function clientReadsEntity(): array
    {
        $r = self::fixture()['client_reads'];

        return [new Entity('items', 't_items', $r['entity']['pk'], $r['entity']['columns'], ['list', 'get', 'create', 'update']), $r];
    }

    /** @param list<array<string, mixed>> $writes */
    private static function clientReadsPlane(array $rows, array &$writes = []): RemoteAdapter
    {
        return new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use ($rows, &$writes): array {
            if ($m !== 'GET') {
                $writes[] = json_decode((string) $body, true);
            }

            return ['status' => $m === 'POST' ? 201 : 200, 'body' => (string) json_encode($rows)];
        });
    }

    /**
     * Rows the client returns carry no `tenant_id`; a stored document keeps its
     * own; a client created to keep the tenant returns the row as stored.
     */
    // data-client AC-21 — Rows the client returns carry no tenant_id
    // data-client AC-22 — A stored document is returned as stored
    // data-client AC-23 — An App that must read tenant_id says so
    #[Group('spec:data-client:AC-21')]
    #[Group('spec:data-client:AC-22')]
    #[Group('spec:data-client:AC-23')]
    #[Group('spec:data-client')]
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    public function testRowsTheClientReturnsCarryNoTenantId(): void
    {
        self::requireFixture();
        [$e, $r] = self::clientReadsEntity();
        $mock = static fn (bool $keep = false): Client => new Client(AdapterRegistry::create('mock', ['seed' => ['items' => [$r['row']]]]), ['items' => $e], keepTenantId: $keep);

        $db = $mock();
        $this->assertSame($r['answer'], $db->get($e, 'r1'));
        $this->assertSame([$r['answer']], $db->list($e));
        $this->assertSame([$r['answer']], $db->page($e)['items']);

        // Every write answers the row without the tenant.
        $db = new Client(self::clientReadsPlane([$r['row']]), ['items' => $e]);
        $this->assertSame($r['answer'], $db->create($e, ['id' => 'r1', 'code' => 'one']));
        $this->assertSame([$r['answer']], $db->createMany($e, [['id' => 'r1', 'code' => 'one']]));
        $this->assertSame($r['answer'], $db->update($e, 'r1', ['code' => 'one']));
        $this->assertSame($r['answer'], $db->updateIf($e, 'r1', ['code' => 'one'], ['code' => 'one']));

        // The mounted collection, and the read sent back as PUT: the documents
        // are written back as stored.
        $app = new App(['name' => 'items']);
        Crud::mount($app, $mock(), $e, ['path' => '/items']);
        $this->assertSame([$r['answer']], FakeContext::call($app, 'GET', '/items')['data']['items']);
        $read = FakeContext::call($app, 'GET', '/items/r1')['data'];
        $this->assertSame($r['answer'], $read);
        $writes = [];
        $routed = new App(['name' => 'items']);
        Crud::mount($routed, new Client(self::clientReadsPlane([$r['row']], $writes), ['items' => $e]), $e, ['path' => '/items']);
        $put = FakeContext::call($routed, 'PUT', '/items/r1', ['body' => $read]);
        $this->assertSame([200, $r['answer']], [$put['status'], $put['data']], $put['raw']);
        $this->assertSame([['code' => $r['row']['code'], 'items' => $r['row']['items'], 'payload' => $r['row']['payload']]], $writes);

        // A client created to keep the tenant.
        $db = $mock(true);
        $this->assertSame($r['row'], $db->get($e, 'r1'));
        $this->assertSame([$r['row']], $db->list($e));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function embedCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['client_reads']['embeds'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    // data-client AC-21 — Rows the client returns carry no tenant_id
    #[Group('spec:data-client:AC-21')]
    #[Group('spec:data-client')]
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('embedCases')]
    public function testAListWithEmbedsIsStrippedAsTheFixtureSays(array $case): void
    {
        self::requireFixture();
        [$e] = self::clientReadsEntity();
        $db = new Client(self::clientReadsPlane($case['rows']), ['items' => $e]);
        // Compared as JSON, so an emptied embedded row is `{}`, as in JavaScript.
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), false, flags: JSON_THROW_ON_ERROR);
        $want = null;
        foreach ($fixture->client_reads->embeds as $embed) {
            if ($embed->name === $case['name']) {
                $want = $embed->answer;
            }
        }
        $this->assertSame((string) json_encode($want), (string) json_encode($db->list($e, ['select' => $case['select']])));
    }
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function putTenantCases(): iterable
    {
        if (! is_file(self::FIXTURE)) {
            yield from self::absent(1);

            return;
        }
        foreach (self::fixture()['put_tenant']['cases'] as $case) {
            // `Crud::mount` takes the client only.
            if ($case['repo'] === 'client') {
                yield $case['name'] => [$case];
            }
        }
    }

    /** @param array<string, mixed> $case */
    // crud-routes AC-14 — A PUT naming tenant_id is settled against the request's tenant
    #[Group('spec:crud-routes:AC-14')]
    #[Group('spec:crud-routes')]
    #[Group('spec:runtime-parity:AC-9')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('putTenantCases')]
    public function testAPutNamingTheTenantIsSettledAgainstTheRequest(array $case): void
    {
        self::requireFixture();
        $t = self::fixture()['put_tenant'];
        $e = new Entity('items', 't_items', 'id', $t['columns'], ['list', 'get', 'update']);
        $app = new App(['name' => 'items']);
        Crud::mount($app, new Client(AdapterRegistry::create('mock', ['seed' => ['items' => [$t['row']]]]), ['items' => $e]), $e, ['path' => '/items']);
        $res = FakeContext::call($app, 'PUT', '/items/r1', ['body' => $case['data'], 'headers' => ['x-revenexx-tenant' => $t['tenant']]]);
        $this->assertSame($case['status'], $res['status'], $res['raw']);
        if (isset($case['code'])) {
            $this->assertSame($case['code'], $res['data']['code']);
        }
        if (isset($case['answer'])) {
            $this->assertSame($case['answer'], $res['data']);
        }
    }
}
