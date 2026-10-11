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
            'create' => static fn () => $db->create($e, ['code' => 'x']),
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
        if (isset($case['unknown'])) {
            self::assertSame($case['unknown'], $res['data']['details']['unknown']);
        }
        if (isset($case['allowed'])) {
            self::assertSame($case['allowed'], $res['data']['details']['allowed']);
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
}
