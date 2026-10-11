<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * App settings — the tenant's configuration for THIS app, read at runtime.
 *
 *   GET {gateway}/v1/settings/apps/{app}[?market={code}]
 *     -> { app, market, settings: { … }, masked: [ … ] }
 *
 * Precedence is the gateway's (market override → stored tenant value →
 * settings.json default); this reader never re-implements it. Its own
 * `defaults` are a last layer, used only where the gateway resolved nothing or
 * could not be reached. It FAILS OPEN (never throws) and CACHES per (tenant,
 * app, market) — build it once, at module scope, so the cache outlives a
 * request: open-runtimes loads the entrypoint once per process.
 *
 * There is no in-flight sharing between concurrent reads: a PHP worker serves one
 * request at a time.
 */
final class Settings
{
    public const DEFAULT_GATEWAY_ENDPOINT = 'https://api.revenexx.com';
    public const DEFAULT_TTL_MS = 60_000;
    public const DEFAULT_ERROR_TTL_MS = 5_000;
    public const DEFAULT_TIMEOUT_MS = 2_000;

    private string $app;
    /** @var array<string, mixed> */
    private array $defaults;
    private string $endpoint;
    private int $ttlMs;
    private int $errorTtlMs;
    private int $timeoutMs;
    /** @var callable(string, array<int, string>, int): array{status: int, body: string} */
    private $transport;
    /** @var callable(): float */
    private $clock;
    /** @var ?callable(\Throwable, array{app: string, tenant: string, market: ?string}): void */
    private $onError;

    /** @var array<string, array{at: float, ttl: int, snapshot: array<string, mixed>}> */
    private array $cache = [];
    /** @var array<string, array<string, mixed>> */
    private array $lastGood = [];

    /**
     * @param array{
     *   app?: string, defaults?: array<string, mixed>, endpoint?: string, ttlMs?: int, errorTtlMs?: int,
     *   timeoutMs?: int, env?: array<string, string|false|null>, transport?: callable, clock?: callable,
     *   onError?: callable
     * } $options
     */
    public function __construct(array $options = [])
    {
        $env = $options['env'] ?? null;
        $read = static function (string $name) use ($env): string {
            $value = \is_array($env) ? ($env[$name] ?? null) : getenv($name);

            return \is_string($value) ? $value : '';
        };
        $this->app = trim((string) (($options['app'] ?? '') ?: $read('REVENEXX_APP_NAME')));
        $this->defaults = $options['defaults'] ?? [];
        $this->endpoint = rtrim((string) (($options['endpoint'] ?? '') ?: ($read('REVENEXX_GATEWAY_ENDPOINT') ?: self::DEFAULT_GATEWAY_ENDPOINT)), '/');
        $this->ttlMs = (int) ($options['ttlMs'] ?? (($read('REVENEXX_SETTINGS_TTL_MS') ?: self::DEFAULT_TTL_MS)));
        $this->errorTtlMs = (int) ($options['errorTtlMs'] ?? self::DEFAULT_ERROR_TTL_MS);
        $this->timeoutMs = (int) ($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS);
        $this->transport = $options['transport'] ?? self::curlTransport();
        $this->clock = $options['clock'] ?? static fn (): float => microtime(true) * 1000;
        $this->onError = $options['onError'] ?? null;
    }

    /**
     * The effective settings for this request. Never throws.
     *
     * @param mixed $source a RouteContext, an open-runtimes context, or a header array
     * @return array<string, mixed>
     */
    public function get(mixed $source = null, ?string $market = null): array
    {
        return $this->load($source, $market)['settings'];
    }

    /**
     * The same read, plus app, tenant, market, masked keys and provenance.
     *
     * @return array{app: string, tenant: string, market: ?string, settings: array<string, mixed>, masked: list<string>, resolved: bool, error?: string}
     */
    public function load(mixed $source = null, ?string $market = null): array
    {
        $headers = self::headersOf($source);
        $tenant = $headers['x-revenexx-tenant'] ?? '';
        if ($source instanceof Router\RouteContext && $source->tenant !== null) {
            $tenant = $source->tenant;
        }
        $market = trim($market ?? ($headers['x-revenexx-market'] ?? ''));
        $market = $market === '' ? null : $market;

        if ($this->app === '') {
            return $this->fallback('', $tenant, $market, 'no app name (pass `app` or set REVENEXX_APP_NAME)');
        }
        if ($tenant === '') {
            return $this->fallback($this->app, '', $market, 'no tenant on the request');
        }

        $key = "{$tenant} {$this->app} " . ($market ?? '');
        $now = ($this->clock)();
        $hit = $this->cache[$key] ?? null;
        if ($hit !== null && $now - $hit['at'] < $hit['ttl']) {
            return $hit['snapshot'];
        }

        $token = $headers['x-revenexx-context'] ?? '';
        try {
            $snapshot = $this->request($tenant, $token, $market);
            $this->lastGood[$key] = $snapshot;
            $this->cache[$key] = ['at' => $now, 'ttl' => $this->ttlMs, 'snapshot' => $snapshot];

            return $snapshot;
        } catch (\Throwable $e) {
            // A 404 is "not installed, or declares no settings" — a stable
            // answer, cached for the full TTL rather than re-asked every 5s.
            $ttl = $e->getCode() === 404 ? $this->ttlMs : $this->errorTtlMs;
            $this->report($e, $tenant, $market);
            $stale = $this->lastGood[$key] ?? null;
            $snapshot = $stale !== null
                ? [...$stale, 'resolved' => false, 'error' => $e->getMessage()]
                : $this->fallback($this->app, $tenant, $market, $e->getMessage());
            $this->cache[$key] = ['at' => $now, 'ttl' => $ttl, 'snapshot' => $snapshot];

            return $snapshot;
        }
    }

    /** Drop the cache (tests, or right after writing settings). */
    public function clear(): void
    {
        $this->cache = [];
        $this->lastGood = [];
    }

    /** @return array{app: string, tenant: string, market: ?string, settings: array<string, mixed>, masked: list<string>, resolved: bool} */
    private function request(string $tenant, string $token, ?string $market): array
    {
        $url = "{$this->endpoint}/v1/settings/apps/" . rawurlencode($this->app) . ($market !== null ? '?market=' . rawurlencode($market) : '');
        $headers = ['Accept: application/json', 'X-Revenexx-Tenant: ' . $tenant];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $answer = ($this->transport)($url, $headers, $this->timeoutMs);
        $status = (int) ($answer['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("settings: {$status} for {$this->app}", $status);
        }
        $body = json_decode((string) ($answer['body'] ?? ''), true);
        $body = \is_array($body) ? $body : [];
        $masked = array_values(array_filter((array) ($body['masked'] ?? []), 'is_string'));

        return [
            'app' => $this->app,
            'tenant' => $tenant,
            'market' => $market,
            'settings' => $this->merge(\is_array($body['settings'] ?? null) ? $body['settings'] : []),
            'masked' => $masked,
            'resolved' => true,
        ];
    }

    /**
     * The gateway's resolved map over the declared defaults. A null from the
     * gateway does not clobber a default; a real stored value — `false`, `0`,
     * `''` included — always wins.
     *
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function merge(array $resolved): array
    {
        $out = $this->defaults;
        foreach ($resolved as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            } elseif (! \array_key_exists($key, $out)) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /** @return array{app: string, tenant: string, market: ?string, settings: array<string, mixed>, masked: list<string>, resolved: bool, error: string} */
    private function fallback(string $app, string $tenant, ?string $market, string $error): array
    {
        return ['app' => $app, 'tenant' => $tenant, 'market' => $market, 'settings' => $this->defaults, 'masked' => [], 'resolved' => false, 'error' => $error];
    }

    private function report(\Throwable $e, string $tenant, ?string $market): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($e, ['app' => $this->app, 'tenant' => $tenant, 'market' => $market]);
        } catch (\Throwable) {
            // An error reporter must never be the reason a request fails.
        }
    }

    /** @return array<string, string> */
    private static function headersOf(mixed $source): array
    {
        if ($source instanceof Router\RouteContext) {
            return $source->headers;
        }

        return Adapter\RuntimeAdapter::headers($source);
    }

    /** @return callable(string, array<int, string>, int): array{status: int, body: string} */
    private static function curlTransport(): callable
    {
        return static function (string $url, array $headers, int $timeoutMs): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException("settings: {$error}");
            }

            return ['status' => $status, 'body' => (string) $body];
        };
    }
}
