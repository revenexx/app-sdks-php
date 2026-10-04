<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Refusal;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Tests\Support\FakeContext;
use Revenexx\AppSdk\Tests\Support\PlatformException;

/** Reading a refusal (RAD-98) — mirrors packages/sdk/test/refusals.test.ts. */
final class RefusalTest extends TestCase
{
    // cross-app-refusals AC-1 — An App's refusal reaches the platform client with its reason and code
    #[Group('spec:cross-app-refusals:AC-1')]
    #[Group('spec:cross-app-refusals')]
    public function testAnAppsRefusalReachesThePlatformClientWhole(): void
    {
        $app = new App(['name' => 'cost-centers']);
        $app->get('/x', static fn () => throw new HttpError(409, 'the cost centre has no active budget', null, 'no_active_budget'));
        $res = FakeContext::call($app, 'GET', '/x');

        // What the PHP platform client does with the answer.
        $caught = new PlatformException($res['data']['message'], $res['status'], $res['data']['type'] ?? '', $res['raw']);
        $this->assertSame(['the cost centre has no active budget', 409, 'no_active_budget'], [$caught->getMessage(), $caught->getCode(), $caught->getType()]);
    }

    // cross-app-refusals AC-2 — Another App's refusal is read whole
    #[Group('spec:cross-app-refusals:AC-2')]
    #[Group('spec:cross-app-refusals')]
    public function testAnotherAppsRefusalIsReadWhole(): void
    {
        $r = Refusal::of(PlatformException::thrownFrom(['kind' => 'platform', 'status' => 409, 'message' => null, 'response' => '{"error":"no budget","code":"no_active_budget","details":{"cost_center":"cc1"}}', 'app' => 'cost-centers']));
        $this->assertSame([409, 'no_active_budget', 'no budget', ['cost_center' => 'cc1'], 'cost-centers'], [$r?->status, $r?->code, $r?->message, $r?->details, $r?->app]);
    }

    // cross-app-refusals AC-3 — A body short of the router's shape still yields a reason
    #[Group('spec:cross-app-refusals:AC-3')]
    #[Group('spec:cross-app-refusals')]
    public function testABodyShortOfTheRoutersShapeStillYieldsAReason(): void
    {
        $a = Refusal::of(PlatformException::thrownFrom(['kind' => 'platform', 'status' => 404, 'message' => 'gone', 'response' => '{"message":"gone","type":"not_found"}']));
        $this->assertSame(['not_found', 'gone'], [$a?->code, $a?->message]);
        $b = Refusal::of(PlatformException::thrownFrom(['kind' => 'platform', 'status' => 502, 'message' => 'bad gateway', 'response' => '<html>']));
        $this->assertSame([502, null, 'bad gateway'], [$b?->status, $b?->code, $b?->message]);
    }

    // cross-app-refusals AC-4 — This App's own refusal is read the same way
    #[Group('spec:cross-app-refusals:AC-4')]
    #[Group('spec:cross-app-refusals')]
    public function testThisAppsOwnRefusalIsReadTheSameWay(): void
    {
        $r = Refusal::of(HttpError::badRequest('bad', ['field' => 'x']));
        $this->assertSame([400, 'bad_request', 'bad', ['field' => 'x'], null], [$r?->status, $r?->code, $r?->message, $r?->details, $r?->app]);
    }

    // cross-app-refusals AC-5 — Anything else is not a refusal
    #[Group('spec:cross-app-refusals:AC-5')]
    #[Group('spec:cross-app-refusals')]
    public function testAnythingElseIsNotARefusal(): void
    {
        $this->assertNull(Refusal::of(new \TypeError('x')));
        $this->assertNull(Refusal::of(PlatformException::thrownFrom(['kind' => 'platform', 'status' => 0, 'message' => 'Endpoint must be a valid string', 'response' => ''])));
        $this->assertNull(Refusal::of(null));
        $this->assertNull(Refusal::of('boom'));
    }

    // cross-app-refusals AC-6 — An uncaught refusal is logged as what the far side said
    #[Group('spec:cross-app-refusals:AC-6')]
    #[Group('spec:cross-app-refusals')]
    public function testAnUncaughtRefusalIsLoggedAsWhatTheFarSideSaid(): void
    {
        $app = new App(['name' => 'procurement']);
        $app->get('/submit', static fn () => throw PlatformException::thrownFrom(['kind' => 'platform', 'status' => 409, 'message' => null, 'response' => '{"error":"no budget","code":"no_active_budget"}', 'app' => 'cost-centers']));
        $res = FakeContext::call($app, 'GET', '/submit');

        $this->assertSame([500, 'internal_error'], [$res['status'], $res['data']['code']]);
        $this->assertStringContainsString('refused by cost-centers: 409 no_active_budget no budget', implode("\n", $res['logs']));
    }
}
