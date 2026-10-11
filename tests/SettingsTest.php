<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\RouteContext;
use Revenexx\AppSdk\Settings;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * The settings reader — the same cases the JavaScript SDK tests. The fake
 * gateway applies the gateway's own precedence (market override → tenant value
 * → schema default) so the tests assert that the reader asks for the right
 * thing and returns the answer unmangled.
 */
final class SettingsTest extends TestCase
{
    private const SCHEMA = [
        'default_page_status' => ['scope' => 'tenant', 'default' => 'draft'],
        'default_source_language' => ['scope' => 'market', 'default' => 'de'],
        'translate_endpoint' => ['scope' => 'tenant'],
        'translate_api_key' => ['scope' => 'tenant', 'sensitive' => true],
    ];

    /** @var list<array{url: string, headers: list<string>}> */
    private array $calls = [];
    private float $now = 1_000_000.0;

    /**
     * @param array<string, mixed> $values  stored tenant values; `__markets` holds per-market overrides
     * @param ?callable(string): (array{status: int, body: string}|null) $override
     */
    private function gateway(array $schema = self::SCHEMA, array $values = [], ?callable $override = null): callable
    {
        return function (string $url, array $headers) use ($schema, $values, $override): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers];
            if ($override !== null && ($forced = $override($url)) !== null) {
                return $forced;
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $market = (string) ($q['market'] ?? '');
            $settings = [];
            $masked = [];
            foreach ($schema as $key => $def) {
                if ($def['sensitive'] ?? false) {
                    $masked[] = $key;
                    continue;
                }
                $value = $def['default'] ?? null;
                if (isset($values[$key])) {
                    $value = $values[$key];
                }
                if (($def['scope'] ?? '') === 'market' && $market !== '' && isset($values['__markets'][$market][$key])) {
                    $value = $values['__markets'][$market][$key];
                }
                $settings[$key] = $value;
            }

            return ['status' => 200, 'body' => (string) json_encode(['settings' => $settings, 'masked' => $masked])];
        };
    }

    /** @param array<string, mixed> $options */
    private function reader(array $options): Settings
    {
        return new Settings(['app' => 'pages', 'env' => [], 'clock' => fn (): float => $this->now, ...$options]);
    }

    /** @return array<string, string> */
    private static function request(?string $market = null): array
    {
        return ['x-revenexx-tenant' => 'acme', 'x-revenexx-context' => 'jwt-token', ...($market !== null ? ['x-revenexx-market' => $market] : [])];
    }

    // app-settings AC-1 — Settings are read for the App, the tenant and the caller's identity
    // app-settings AC-3 — The App's default stands in where nothing was resolved
    #[Group('spec:app-settings:AC-1')]
    #[Group('spec:app-settings:AC-3')]
    #[Group('spec:app-settings')]
    public function testReturnsTheDeclaredDefaultsWhenNothingIsStored(): void
    {
        $s = $this->reader(['defaults' => ['default_page_status' => 'draft', 'translate_endpoint' => ''], 'transport' => $this->gateway()])->get(self::request());

        $this->assertSame(['draft', ''], [$s['default_page_status'], $s['translate_endpoint']]);
        $this->assertSame(Settings::DEFAULT_GATEWAY_ENDPOINT . '/v1/settings/apps/pages', $this->calls[0]['url']);
        $this->assertContains('X-Revenexx-Tenant: acme', $this->calls[0]['headers']);
        $this->assertContains('Authorization: Bearer jwt-token', $this->calls[0]['headers']);
    }

    // app-settings AC-2 — The resolved value is returned as the gateway resolved it
    #[Group('spec:app-settings:AC-2')]
    #[Group('spec:app-settings')]
    public function testAStoredValueWinsIncludingAFalsyOne(): void
    {
        $this->assertSame('published', $this->reader(['defaults' => ['default_page_status' => 'draft'], 'transport' => $this->gateway(self::SCHEMA, ['default_page_status' => 'published'])])->get(self::request())['default_page_status']);
        $flag = $this->reader(['defaults' => ['enabled' => true], 'transport' => $this->gateway(['enabled' => ['default' => true]], ['enabled' => false])]);
        $this->assertFalse($flag->get(self::request())['enabled']);
    }

    // app-settings AC-4 — Settings resolve for the request's market
    #[Group('spec:app-settings:AC-4')]
    #[Group('spec:app-settings')]
    public function testResolvesForTheRequestsMarketAndAnExplicitOne(): void
    {
        $r = $this->reader(['transport' => $this->gateway(self::SCHEMA, ['default_source_language' => 'de', '__markets' => ['fr' => ['default_source_language' => 'fr']]])]);

        $this->assertSame('de', $r->get(self::request())['default_source_language']);
        $this->assertSame('fr', $r->get(self::request('fr'))['default_source_language']);
        $this->assertStringEndsWith('?market=fr', $this->calls[1]['url']);
        $this->assertSame('fr', $r->get(self::request('de'), 'fr')['default_source_language']);
    }

    // app-settings AC-5 — Sensitive values are withheld and named
    #[Group('spec:app-settings:AC-5')]
    #[Group('spec:app-settings')]
    public function testWithholdsSensitiveValuesButNamesThem(): void
    {
        $snap = $this->reader(['transport' => $this->gateway(self::SCHEMA, ['translate_api_key' => 'secret'])])->load(self::request());
        $this->assertArrayNotHasKey('translate_api_key', $snap['settings']);
        $this->assertSame(['translate_api_key'], $snap['masked']);
    }

    // app-settings AC-6 — An unreachable or silent gateway falls back to the defaults
    #[Group('spec:app-settings:AC-6')]
    #[Group('spec:app-settings')]
    public function testFailsOpenToTheDefaults(): void
    {
        $errors = [];
        $down = $this->reader([
            'defaults' => ['registration_mode' => 'open'],
            'transport' => static fn () => throw new \RuntimeException('ECONNREFUSED'),
            'onError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        ])->load(self::request());
        $this->assertSame([['registration_mode' => 'open'], false], [$down['settings'], $down['resolved']]);
        $this->assertStringContainsString('ECONNREFUSED', $down['error']);
        $this->assertCount(1, $errors);

        $notInstalled = $this->reader(['defaults' => ['auto_reserve' => true], 'transport' => $this->gateway([], [], static fn (): array => ['status' => 404, 'body' => '{}'])]);
        $this->assertTrue($notInstalled->get(self::request())['auto_reserve']);
    }

    // app-settings AC-7 — The last resolved values outlive a gateway blip
    #[Group('spec:app-settings:AC-7')]
    #[Group('spec:app-settings')]
    public function testKeepsTheLastResolvedValuesThroughABlip(): void
    {
        $down = false;
        $inner = $this->gateway(self::SCHEMA, ['default_page_status' => 'published']);
        $r = $this->reader(['defaults' => ['default_page_status' => 'draft'], 'ttlMs' => 0, 'transport' => static function (string $u, array $h) use (&$down, $inner): array {
            if ($down) {
                throw new \RuntimeException('gateway unreachable');
            }

            return $inner($u, $h);
        }]);
        $this->assertSame('published', $r->get(self::request())['default_page_status']);
        $down = true;
        $this->now += 1;
        $snap = $r->load(self::request());
        $this->assertSame(['published', false], [$snap['settings']['default_page_status'], $snap['resolved']]);
    }

    // app-settings AC-8 — Without a tenant the defaults answer at once
    #[Group('spec:app-settings:AC-8')]
    #[Group('spec:app-settings')]
    public function testWithoutATenantTheDefaultsAnswerAtOnce(): void
    {
        $snap = $this->reader(['defaults' => ['default_page_status' => 'draft'], 'transport' => $this->gateway()])->load([]);
        $this->assertSame('draft', $snap['settings']['default_page_status']);
        $this->assertSame([], $this->calls);
    }

    // app-settings AC-9 — Answers are cached per tenant, App and market
    #[Group('spec:app-settings:AC-9')]
    #[Group('spec:app-settings')]
    public function testCachesPerTenantAppAndMarket(): void
    {
        $r = $this->reader(['ttlMs' => 60_000, 'transport' => $this->gateway(self::SCHEMA, ['__markets' => ['fr' => ['default_source_language' => 'fr']]])]);
        for ($i = 0; $i < 25; $i++) {
            $r->get(self::request('de'));
        }
        $this->assertCount(1, $this->calls);
        $this->assertSame('fr', $r->get(self::request('fr'))['default_source_language']);
        $this->assertCount(2, $this->calls);
        $this->now += 59_000;
        $r->get(self::request('de'));
        $this->assertCount(2, $this->calls);
        $this->now += 2_000;
        $r->get(self::request('de'));
        $this->assertCount(3, $this->calls);
        $r->clear();
        $r->get(self::request('de'));
        $this->assertCount(4, $this->calls);
    }

    // app-settings AC-10 — A failed read is retried sooner than a good one
    #[Group('spec:app-settings:AC-10')]
    #[Group('spec:app-settings')]
    public function testRetriesAFailedReadSooner(): void
    {
        $fail = true;
        $count = 0;
        $r = $this->reader(['ttlMs' => 60_000, 'errorTtlMs' => 5_000, 'transport' => static function () use (&$fail, &$count): array {
            $count++;
            if ($fail) {
                throw new \RuntimeException('gateway unreachable');
            }

            return ['status' => 200, 'body' => '{"settings":{"a":1},"masked":[]}'];
        }]);
        $r->get(self::request());
        $this->now += 6_000;
        $fail = false;
        $this->assertSame(1, $r->get(self::request())['a']);
        $this->assertSame(2, $count);
    }

    // app-settings AC-11 — Every route context can read the settings
    #[Group('spec:app-settings:AC-11')]
    #[Group('spec:app-settings')]
    public function testEveryRouteContextCanReadTheSettings(): void
    {
        $app = new App(['name' => 'pages', 'settings' => ['defaults' => ['default_page_status' => 'draft', 'default_source_language' => 'de'], 'env' => [], 'transport' => $this->gateway(self::SCHEMA, ['default_page_status' => 'published', '__markets' => ['fr' => ['default_source_language' => 'fr']]])]]);
        $app->get('/pages/config', static fn (RouteContext $c) => $c->json($c->settings()));
        $fr = FakeContext::call($app, 'GET', '/pages/config', ['headers' => ['x-revenexx-market' => 'fr']]);
        $this->assertSame(['published', 'fr'], [$fr['data']['default_page_status'], $fr['data']['default_source_language']]);
        $this->assertStringContainsString('/v1/settings/apps/pages', $this->calls[0]['url']);

        $down = new App(['name' => 'orders', 'settings' => ['defaults' => ['auto_reserve' => true], 'env' => [], 'transport' => static fn () => throw new \RuntimeException('down')]]);
        $down->get('/orders/config', static fn (RouteContext $c) => $c->json($c->settings()));
        $this->assertSame(['status' => 200, 'data' => ['auto_reserve' => true]], array_intersect_key(FakeContext::call($down, 'GET', '/orders/config'), ['status' => 0, 'data' => 0]));

        $shared = $this->reader(['transport' => $this->gateway()]);
        $this->assertSame($shared, (new App(['name' => 'pages', 'settings' => $shared]))->settings());
    }
}
