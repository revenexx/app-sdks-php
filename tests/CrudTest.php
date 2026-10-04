<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/** `Crud::mount` — mirrors the mountCrud half of packages/sdk/test/router.test.ts. */
final class CrudTest extends TestCase
{
    /** @param list<string> $ops */
    private static function markets(array $ops = ['list', 'get', 'create', 'update', 'delete']): Entity
    {
        return new Entity('markets', 't_markets', 'id', [
            'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
            'code' => ['type' => 'text', 'notNull' => true],
            'currency' => ['type' => 'text', 'notNull' => true],
            'labels' => ['type' => 'jsonb'],
            'created_at' => ['type' => 'timestamptz', 'default' => 'now()'],
        ], $ops);
    }

    private static function locales(): Entity
    {
        return new Entity('locales', 't_locales', 'id', [
            'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
            'market_id' => ['type' => 'uuid', 'notNull' => true],
            'code' => ['type' => 'text', 'notNull' => true],
        ], ['list', 'get', 'create', 'update', 'delete']);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $seed
     * @param array<string, mixed>                      $parent extra parent options
     * @return array{App, Client}
     */
    private static function build(array $seed = [], array $parent = [], ?Client $client = null): array
    {
        $m = self::markets();
        $l = self::locales();
        $db = $client ?? new Client(AdapterRegistry::create('mock', ['seed' => $seed]), ['markets' => $m, 'locales' => $l]);
        $app = new App(['name' => 'markets']);
        Crud::mount($app, $db, $m, ['path' => '/markets']);
        Crud::mount($app, $db, $l, ['path' => '/markets/{marketId}/locales', 'parent' => ['param' => 'marketId', 'column' => 'market_id', ...$parent]]);

        return [$app, $db];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function three(): array
    {
        return ['markets' => [
            ['id' => 'm1', 'code' => 'de', 'currency' => 'EUR'],
            ['id' => 'm2', 'code' => 'at', 'currency' => 'EUR'],
            ['id' => 'm3', 'code' => 'us', 'currency' => 'USD'],
        ]];
    }

    // crud-routes AC-1 — The list answers rows with paging metadata
    // list-filters AC-1 — A column key filters by exact value
    #[Group('spec:crud-routes:AC-1')]
    #[Group('spec:list-filters:AC-1')]
    #[Group('spec:crud-routes')]
    #[Group('spec:list-filters')]
    public function testListsWithPagingMetadataAndColumnFilters(): void
    {
        [$app] = self::build(self::three());

        $all = FakeContext::call($app, 'GET', '/markets', ['query' => 'limit=2']);
        $this->assertSame(['limit' => 2, 'offset' => 0, 'total' => 3, 'returned' => 2, 'hasMore' => true], $all['data']['page']);
        $this->assertCount(2, $all['data']['items']);

        $usd = FakeContext::call($app, 'GET', '/markets', ['query' => 'currency=USD&order=code.asc']);
        $this->assertSame(1, $usd['data']['page']['total']);
        $this->assertSame(['currency' => 'USD'], $usd['data']['filter']);
    }

    // crud-routes AC-2 — A page past the end is an empty 200 with the real total
    #[Group('spec:crud-routes:AC-2')]
    #[Group('spec:crud-routes')]
    public function testThePageAfterTheLastIsAnEmpty200WithTheRealTotal(): void
    {
        $e = self::markets();
        $adapter = new RemoteAdapter('http://pgrst:3000', transport: static fn (): array => [
            'status' => 416,
            'body' => '{"code":"PGRST103","details":"An offset of 100 was requested, but there are only 7 rows.","message":"Requested range not satisfiable"}',
        ]);
        [$app] = self::build([], [], new Client($adapter, ['markets' => $e, 'locales' => self::locales()]));

        $res = FakeContext::call($app, 'GET', '/markets', ['query' => 'limit=25&offset=100']);
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['data']['items']);
        $this->assertSame(['limit' => 25, 'offset' => 100, 'total' => 7, 'returned' => 0, 'hasMore' => false], $res['data']['page']);
    }

    // crud-routes AC-3 — The five routes round-trip a record
    #[Group('spec:crud-routes:AC-3')]
    #[Group('spec:crud-routes')]
    public function testTheFiveRoutesRoundTripARecord(): void
    {
        [$app] = self::build();

        $created = FakeContext::call($app, 'POST', '/markets', ['body' => ['code' => 'de', 'currency' => 'EUR']]);
        $this->assertSame(201, $created['status']);
        $id = $created['data']['id'];
        $this->assertSame('de', FakeContext::call($app, 'GET', "/markets/{$id}")['data']['code']);
        $this->assertSame('CHF', FakeContext::call($app, 'PUT', "/markets/{$id}", ['body' => ['currency' => 'CHF']])['data']['currency']);
        $this->assertSame(['deleted' => true, 'id' => $id], FakeContext::call($app, 'DELETE', "/markets/{$id}")['data']);
        $this->assertSame(404, FakeContext::call($app, 'GET', "/markets/{$id}")['status']);
    }

    // crud-routes AC-4 — An update with an empty body is a 400
    #[Group('spec:crud-routes:AC-4')]
    #[Group('spec:crud-routes')]
    public function testAnEmptyUpdateBodyIsA400(): void
    {
        [$app] = self::build(['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR']]]);

        $empty = FakeContext::call($app, 'PUT', '/markets/m1', ['body' => []]);
        $this->assertSame([400, 'empty_update'], [$empty['status'], $empty['data']['code']]);
        $this->assertSame(400, FakeContext::call($app, 'PUT', '/markets/m1')['status']);
        $this->assertSame(404, FakeContext::call($app, 'PUT', '/markets/nope', ['body' => []])['status']);
        $this->assertSame('USD', FakeContext::call($app, 'PUT', '/markets/m1', ['body' => ['currency' => 'USD']])['data']['currency']);
    }

    // crud-routes AC-5 — A nested collection is scoped to the parent in its path
    // crud-routes AC-6 — A record cannot be attached to, or reached through, another parent
    #[Group('spec:crud-routes:AC-5')]
    #[Group('spec:crud-routes:AC-6')]
    #[Group('spec:crud-routes')]
    public function testScopesNestedResourcesToTheirParent(): void
    {
        [$app] = self::build([
            'markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR'], ['id' => 'm2', 'code' => 'at', 'currency' => 'EUR']],
            'locales' => [['id' => 'l1', 'market_id' => 'm1', 'code' => 'de-DE'], ['id' => 'l2', 'market_id' => 'm2', 'code' => 'de-AT']],
        ]);

        $this->assertCount(1, FakeContext::call($app, 'GET', '/markets/m1/locales')['data']['items']);
        $created = FakeContext::call($app, 'POST', '/markets/m1/locales', ['body' => ['code' => 'en-GB', 'market_id' => 'm2']]);
        $this->assertSame('m1', $created['data']['market_id']);
        $this->assertSame(404, FakeContext::call($app, 'GET', '/markets/m2/locales/l1')['status']);
        $this->assertSame(404, FakeContext::call($app, 'PUT', '/markets/m2/locales/l1', ['body' => ['code' => 'x']])['status']);
        $this->assertSame(404, FakeContext::call($app, 'DELETE', '/markets/m2/locales/l1')['status']);
        $this->assertSame(200, FakeContext::call($app, 'GET', '/markets/m1/locales/l1')['status']);

        $widened = FakeContext::call($app, 'GET', '/markets/m1/locales', ['query' => 'market_id=m2']);
        $this->assertSame('m1', $widened['data']['filter']['market_id']);
        $this->assertSame(['m1', 'm1'], array_column($widened['data']['items'], 'market_id'));
    }

    // crud-routes AC-7 — An unknown parent is a 404 when the App can look it up
    #[Group('spec:crud-routes:AC-7')]
    #[Group('spec:crud-routes')]
    public function testAnUnknownParentIsA404WhenItCanBeLookedUp(): void
    {
        $seed = ['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR']], 'locales' => [['id' => 'l1', 'market_id' => 'm1', 'code' => 'de_DE']]];
        [$app] = self::build($seed, ['entity' => self::markets()]);

        $this->assertSame(404, FakeContext::call($app, 'GET', '/markets/nope/locales')['status']);
        $this->assertSame(404, FakeContext::call($app, 'POST', '/markets/nope/locales', ['body' => ['code' => 'fr_FR']])['status']);
        $this->assertSame(1, FakeContext::call($app, 'GET', '/markets/m1/locales')['data']['page']['total']);

        [$open] = self::build($seed);
        $ghost = FakeContext::call($open, 'GET', '/markets/nope/locales');
        $this->assertSame([200, 0], [$ghost['status'], $ghost['data']['page']['total']]);
    }

    // crud-routes AC-8 — `only` mounts a subset
    #[Group('spec:crud-routes:AC-8')]
    #[Group('spec:crud-routes')]
    public function testOnlyMountsASubset(): void
    {
        $e = self::markets();
        $db = new Client(AdapterRegistry::create('mock'), ['markets' => $e]);
        $app = new App();
        Crud::mount($app, $db, $e, ['path' => '/markets', 'only' => ['list', 'get']]);

        $this->assertSame(200, FakeContext::call($app, 'GET', '/markets')['status']);
        $this->assertSame(405, FakeContext::call($app, 'POST', '/markets', ['body' => ['code' => 'x']])['status']);
    }

    // crud-routes AC-9 — A missing permission is a 403
    #[Group('spec:crud-routes:AC-9')]
    #[Group('spec:crud-routes')]
    public function testAMissingPermissionIsA403(): void
    {
        $e = self::markets(['list', 'get']);
        $db = new Client(AdapterRegistry::create('mock'), ['markets' => $e]);
        $app = new App();
        Crud::mount($app, $db, $e, ['path' => '/markets']);

        $this->assertSame(403, FakeContext::call($app, 'POST', '/markets', ['body' => ['code' => 'de']])['status']);
    }

    // list-filters AC-2 — A key that names no column is refused
    // list-filters AC-3 — Paging and sorting keys are always accepted
    #[Group('spec:list-filters:AC-2')]
    #[Group('spec:list-filters:AC-3')]
    #[Group('spec:list-filters')]
    public function testAKeyThatNamesNoColumnIsRefused(): void
    {
        [$app] = self::build(['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR'], ['id' => 'm2', 'code' => 'us', 'currency' => 'USD']]]);

        $bad = FakeContext::call($app, 'GET', '/markets', ['query' => 'currency=USD&organization_id=o1&bogus=x']);
        $this->assertSame([400, 'unknown_filter'], [$bad['status'], $bad['data']['code']]);
        $this->assertSame(['organization_id', 'bogus'], $bad['data']['details']['unknown']);
        $this->assertSame(['code', 'created_at', 'currency', 'id', 'labels', 'limit', 'offset', 'order'], $bad['data']['details']['allowed']);
        $this->assertStringContainsString("'organization_id'", $bad['data']['error']);

        $paged = FakeContext::call($app, 'GET', '/markets', ['query' => 'limit=1&offset=1&order=code.desc']);
        $this->assertSame(200, $paged['status']);
        $this->assertSame([], $paged['data']['filter']);
        $this->assertSame(['limit' => 1, 'offset' => 1, 'total' => 2], array_intersect_key($paged['data']['page'], ['limit' => 0, 'offset' => 0, 'total' => 0]));
    }

    // list-filters AC-4 — An App declares the extra keys it reads itself
    #[Group('spec:list-filters:AC-4')]
    #[Group('spec:list-filters')]
    public function testDeclaredQueryParamsAreAcceptedAndNeverFilters(): void
    {
        $e = self::markets();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR']]]]), ['markets' => $e]);
        $app = new App();
        Crud::mount($app, $db, $e, ['path' => '/markets', 'queryParams' => ['include_inactive', 'skus']]);

        $ok = FakeContext::call($app, 'GET', '/markets', ['query' => 'include_inactive=true&skus=in.(a,b)&currency=EUR']);
        $this->assertSame([200, ['currency' => 'EUR'], 1], [$ok['status'], $ok['data']['filter'], $ok['data']['page']['total']]);
        $bad = FakeContext::call($app, 'GET', '/markets', ['query' => 'include_archived=true']);
        $this->assertSame(400, $bad['status']);
        $this->assertContains('include_inactive', $bad['data']['details']['allowed']);

        $bare = new App();
        Crud::mount($bare, $db, $e, ['path' => '/markets', 'columns' => [], 'queryParams' => ['q']]);
        $this->assertSame(200, FakeContext::call($bare, 'GET', '/markets', ['query' => 'limit=5&q=x'])['status']);
        $this->assertSame(['currency'], FakeContext::call($bare, 'GET', '/markets', ['query' => 'currency=EUR'])['data']['details']['unknown']);
    }

    // list-filters AC-5 — An operator in a filter value is refused
    #[Group('spec:list-filters:AC-5')]
    #[Group('spec:list-filters')]
    public function testAnOperatorInAFilterValueIsRefused(): void
    {
        [$app] = self::build(['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR']]]);

        $bad = FakeContext::call($app, 'GET', '/markets', ['query' => 'code=' . rawurlencode('in.(de,us)')]);
        $this->assertSame([400, 'unsupported_filter_operator', ['parameter' => 'code']], [$bad['status'], $bad['data']['code'], $bad['data']['details']]);
        $this->assertStringContainsString('exact value only', $bad['data']['error']);
        foreach (['eq.de', 'neq.de', 'gte.1', 'ilike.*de*', 'is.null', 'not.eq.de', 'not.in.(de)'] as $value) {
            $this->assertSame(400, FakeContext::call($app, 'GET', '/markets', ['query' => 'code=' . rawurlencode($value)])['status'], $value);
        }
        foreach (['de', 'in', 'de.in.x', 'IN.(de)', 'index.html'] as $value) {
            $this->assertSame(200, FakeContext::call($app, 'GET', '/markets', ['query' => 'code=' . rawurlencode($value)])['status'], $value);
        }
    }

    // list-filters AC-6 — A filter on a document column must be a whole document
    #[Group('spec:list-filters:AC-6')]
    #[Group('spec:list-filters')]
    public function testADocumentFilterMustBeAWholeDocument(): void
    {
        [$app] = self::build(['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR', 'labels' => ['de' => 'Deutschland']]]]);

        $bad = FakeContext::call($app, 'GET', '/markets', ['query' => 'labels=x']);
        $this->assertSame([400, 'invalid_value'], [$bad['status'], $bad['data']['code']]);
        $this->assertStringContainsString("filter 'labels'", $bad['data']['error']);
        $this->assertStringContainsString('whole JSON document', $bad['data']['error']);
        $this->assertDoesNotMatchRegularExpression('/[{[]\s*["\]}]/', $bad['data']['error']);
        $this->assertSame(400, FakeContext::call($app, 'GET', '/markets', ['query' => 'labels='])['status']);
        $this->assertSame(200, FakeContext::call($app, 'GET', '/markets', ['query' => 'labels=5'])['status']);
        $this->assertSame(200, FakeContext::call($app, 'GET', '/markets', ['query' => 'code=x'])['status']);
    }

    // list-filters AC-7 — A refusal costs no round trip
    #[Group('spec:list-filters:AC-7')]
    #[Group('spec:list-filters')]
    public function testARefusalCostsNoRoundTrip(): void
    {
        $calls = [];
        $adapter = new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $url) use (&$calls): array {
            $calls[] = $url;

            return ['status' => 200, 'body' => '[{"id":"m1"}]'];
        });
        [$app] = self::build([], ['entity' => self::markets()], new Client($adapter, ['markets' => self::markets(), 'locales' => self::locales()]));

        foreach (['marketId=m2', 'code=' . rawurlencode('in.(de,at)')] as $q) {
            $this->assertSame(400, FakeContext::call($app, 'GET', '/markets/m1/locales', ['query' => $q])['status']);
        }
        $this->assertSame([], $calls);
    }

    // list-filters AC-8 — Query values are decoded exactly once
    #[Group('spec:list-filters:AC-8')]
    #[Group('spec:list-filters')]
    public function testQueryValuesAreDecodedExactlyOnce(): void
    {
        [$app] = self::build(['markets' => [
            ['id' => 'm1', 'code' => 'main', 'currency' => 'EUR'],
            ['id' => 'm2', 'code' => 'Müller & Co', 'currency' => 'EUR'],
            ['id' => 'm3', 'code' => 'a b', 'currency' => 'USD'],
        ]]);

        foreach (['code=m%61in' => 'main', 'code=M%C3%BCller%20%26%20Co' => 'Müller & Co', 'code=a+b' => 'a b', 'code=a%20b' => 'a b'] as $q => $want) {
            $res = FakeContext::call($app, 'GET', '/markets', ['query' => $q]);
            $this->assertSame([1, ['code' => $want]], [$res['data']['page']['total'], $res['data']['filter']], $q);
        }
        $this->assertSame(['code' => '%20'], FakeContext::call($app, 'GET', '/markets', ['query' => 'code=%2520'])['data']['filter']);
        $this->assertSame(200, FakeContext::call($app, 'GET', '/markets', ['query' => 'code=50%'])['status']);
        // The runtime's own `$req->query` arrives undecoded; it is decoded here too.
        $viaQuery = FakeContext::call($app, 'GET', '/markets', ['rawQuery' => ['code' => 'm%61in']]);
        $this->assertSame(['code' => 'main'], $viaQuery['data']['filter']);
    }
}
