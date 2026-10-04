<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Adapter\MockAdapter;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\RouteContext;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/** The PHP router — mirrors packages/sdk/test/router.test.ts. */
final class RouterTest extends TestCase
{
    // router AC-1 — A route matches by method and path, extracting its parameters
    #[Group('spec:router:AC-1')]
    #[Group('spec:router')]
    public function testRoutesByMethodAndPathExtractingParams(): void
    {
        $app = new App(['name' => 'test']);
        $app->get('/things/{id}', static fn (RouteContext $c) => $c->json(['id' => $c->params['id']]));
        $app->get('/markets/:marketId/locales/:id', static fn (RouteContext $c) => $c->json($c->params));

        $this->assertSame(['status' => 200, 'data' => ['id' => '42']], array_intersect_key(FakeContext::call($app, 'GET', '/things/42'), ['status' => 0, 'data' => 0]));
        $this->assertSame(['marketId' => 'm1', 'id' => 'l1'], FakeContext::call($app, 'GET', '/markets/m1/locales/l1')['data']);
    }

    // router AC-2 — A literal segment wins over a parameter
    #[Group('spec:router:AC-2')]
    #[Group('spec:router')]
    public function testPrefersLiteralSegmentsRegardlessOfRegistrationOrder(): void
    {
        $app = new App();
        $app->get('/markets/{id}', static fn (RouteContext $c) => $c->json(['kind' => 'byId', 'id' => $c->params['id']]));
        $app->get('/markets/defaults', static fn (RouteContext $c) => $c->json(['kind' => 'defaults']));

        $this->assertSame(['kind' => 'defaults'], FakeContext::call($app, 'GET', '/markets/defaults')['data']);
        $this->assertSame(['kind' => 'byId', 'id' => 'abc'], FakeContext::call($app, 'GET', '/markets/abc')['data']);
    }

    // router AC-3 — Parameters the gateway extracted win over the router's own
    #[Group('spec:router:AC-3')]
    #[Group('spec:router')]
    public function testGatewayParamsWinOverMatchedParams(): void
    {
        $app = new App();
        $app->get('/things/{id}', static fn (RouteContext $c) => $c->json(['id' => $c->params['id']]));

        $this->assertSame(['id' => 'gw-42'], FakeContext::call($app, 'GET', '/things/42', ['params' => ['id' => 'gw-42']])['data']);
    }

    // router AC-4 — An unknown path is a 404, a known path with another method a 405
    #[Group('spec:router:AC-4')]
    #[Group('spec:router')]
    public function testUnknownPathIs404AndWrongMethodIs405(): void
    {
        $app = new App();
        $app->get('/things', static fn (RouteContext $c) => $c->json([]));

        $nope = FakeContext::call($app, 'GET', '/nope');
        $this->assertSame([404, 'no_route'], [$nope['status'], $nope['data']['code']]);
        $wrong = FakeContext::call($app, 'POST', '/things');
        $this->assertSame([405, 'method_not_allowed'], [$wrong['status'], $wrong['data']['code']]);
    }

    // router AC-5 — `GET /` answers the App's identity and its routes
    #[Group('spec:router:AC-5')]
    #[Group('spec:router')]
    public function testServesTheHealthRouteWithIdentityAndRoutes(): void
    {
        $app = new App(['name' => 'markets', 'healthExtra' => static fn (): array => ['entities' => ['markets']], 'env' => []]);
        $app->get('/markets', static fn (RouteContext $c) => $c->json([]));

        $res = FakeContext::call($app, 'GET', '/');
        $this->assertSame(200, $res['status']);
        $this->assertSame('markets', $res['data']['app']['name']);
        $this->assertSame([['method' => 'GET', 'path' => '/markets']], $res['data']['routes']);
        $this->assertSame(['markets'], $res['data']['entities']);
    }

    // router AC-6 — A thrown `HttpError` becomes its status and a safe body
    #[Group('spec:router:AC-6')]
    #[Group('spec:router')]
    public function testMapsAnHttpErrorToItsStatusAndBody(): void
    {
        $app = new App();
        $app->get('/missing', static fn () => throw HttpError::notFound('gone'));
        $app->get('/teapot', static fn () => throw new HttpError(418, 'short and stout', ['hint' => 'tip me over']));
        $readOnly = new Entity('things', 't', 'id', [], ['list']);
        $db = new Client(new MockAdapter(), ['things' => $readOnly]);
        $app->get('/forbidden', static fn () => $db->create($readOnly, []));

        $this->assertSame(['error' => 'gone', 'message' => 'gone', 'code' => 'not_found', 'type' => 'not_found'], FakeContext::call($app, 'GET', '/missing')['data']);
        $teapot = FakeContext::call($app, 'GET', '/teapot');
        $this->assertSame([418, ['error' => 'short and stout', 'message' => 'short and stout', 'details' => ['hint' => 'tip me over']]], [$teapot['status'], $teapot['data']]);
        $forbidden = FakeContext::call($app, 'GET', '/forbidden');
        $this->assertSame([403, 'not_permitted'], [$forbidden['status'], $forbidden['data']['code']]);
        $this->assertSame(
            [[400, 'bad_request'], [403, 'forbidden'], [409, 'conflict']],
            array_map(static fn (HttpError $e): array => [$e->status, $e->errorCode], [HttpError::badRequest(), HttpError::forbidden(), HttpError::conflict()]),
        );
    }

    // router AC-7 — Any other throw is a 500 that names nothing
    #[Group('spec:router:AC-7')]
    #[Group('spec:router')]
    public function testAnyOtherThrowIsA500ThatNamesNothing(): void
    {
        $app = new App();
        $app->get('/boom', static fn () => throw new \RuntimeException('kaput'));
        $app->get('/sounds-forbidden', static fn () => throw new \RuntimeException('this is not permitted, says the App'));

        $boom = FakeContext::call($app, 'GET', '/boom');
        $this->assertSame([500, ['error' => 'internal error', 'message' => 'internal error', 'code' => 'internal_error', 'type' => 'internal_error']], [$boom['status'], $boom['data']]);
        $this->assertStringNotContainsString('kaput', $boom['raw']);
        $this->assertStringContainsString('kaput', implode("\n", $boom['logs']));
        $this->assertSame(500, FakeContext::call($app, 'GET', '/sounds-forbidden')['status']);
    }

    // router AC-8 — The request arrives parsed
    #[Group('spec:router:AC-8')]
    #[Group('spec:router')]
    public function testParsesTheBodyExposesTheTenantAndTrimsTrailingSlashes(): void
    {
        $app = new App();
        $app->post('/echo', static fn (RouteContext $c) => $c->json(['body' => $c->body, 'tenant' => $c->tenant]));

        $this->assertSame(['body' => ['a' => 1], 'tenant' => 'acme'], FakeContext::call($app, 'POST', '/echo/', ['body' => '{"a":1}'])['data']);
    }

    // acting-person AC-7 — Every route context carries an actor
    #[Group('spec:acting-person:AC-7')]
    #[Group('spec:acting-person')]
    public function testEveryRouteContextCarriesAnActor(): void
    {
        $app = new App();
        $app->get('/who', static fn (RouteContext $c) => $c->json(['plane' => $c->actor->plane, 'trust' => $c->actor->trust, 'contact' => $c->actor->contact]));

        $this->assertSame(['plane' => 'system', 'trust' => 'none', 'contact' => null], FakeContext::call($app, 'GET', '/who')['data']);
        $this->assertSame(
            ['plane' => 'contact', 'trust' => 'asserted', 'contact' => 'c1'],
            FakeContext::call($app, 'GET', '/who', ['headers' => ['x-revenexx-contact' => 'c1']])['data'],
        );
    }

    /** The runtime's own `json()` would encode a list as an object; the router must not. */
    public function testAnswersAListAsAJsonListNotAnObject(): void
    {
        $app = new App();
        $app->get('/list', static fn (RouteContext $c) => $c->json(['items' => [['id' => 1]], 'empty' => []]));

        $this->assertSame('{"items":[{"id":1}],"empty":[]}', FakeContext::call($app, 'GET', '/list')['raw']);
    }
}
