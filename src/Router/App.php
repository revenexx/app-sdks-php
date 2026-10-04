<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Settings;

/**
 * Declarative routing for a revenexx App function on the open-runtimes PHP
 * runtime — the mirror of `createApp` in packages/sdk/src/router/router.ts.
 *
 *   $app = new App(['name' => 'markets', 'settings' => ['defaults' => [...]]]);
 *   $app->get('/markets/{id}', fn (RouteContext $c) => $c->json(...));
 *   return $app->handler();             // the function entrypoint
 *
 * Literal segments match before parameters, the gateway's `x-capability-params`
 * win over the router's own match, the query is decoded exactly once, and
 * whatever a handler throws becomes an answer: an {@see HttpError} its status,
 * code and safe message; anything else a fixed `500 internal_error`, its message
 * logged and never returned.
 */
final class App
{
    /** @var list<array{method: string, template: string, segments: list<array{lit?: string, param?: string}>, literals: int, handler: callable}> */
    private array $routes = [];

    private Settings $settings;

    /**
     * @param array{name?: string, health?: bool, healthExtra?: callable(): array<string, mixed>, settings?: array<string, mixed>|Settings, env?: array<string, string|false|null>} $options
     */
    public function __construct(private readonly array $options = [])
    {
        $settings = $options['settings'] ?? [];
        $this->settings = $settings instanceof Settings
            ? $settings
            : new Settings(['app' => $options['name'] ?? '', ...$settings]);
    }

    public function get(string $template, callable $handler): self
    {
        return $this->on('GET', $template, $handler);
    }

    public function post(string $template, callable $handler): self
    {
        return $this->on('POST', $template, $handler);
    }

    public function put(string $template, callable $handler): self
    {
        return $this->on('PUT', $template, $handler);
    }

    public function patch(string $template, callable $handler): self
    {
        return $this->on('PATCH', $template, $handler);
    }

    public function delete(string $template, callable $handler): self
    {
        return $this->on('DELETE', $template, $handler);
    }

    public function on(string $method, string $template, callable $handler): self
    {
        $segments = array_map(static function (string $seg): array {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $seg, $m)) {
                return ['param' => $m[1]];
            }
            if (str_starts_with($seg, ':') && \strlen($seg) > 1) {
                return ['param' => substr($seg, 1)];
            }

            return ['lit' => $seg];
        }, self::split($template));
        $this->routes[] = [
            'method' => strtoupper($method),
            'template' => $template,
            'segments' => $segments,
            'literals' => \count(array_filter($segments, static fn (array $s): bool => isset($s['lit']))),
            'handler' => $handler,
        ];
        // Literal-first: more literal segments = more specific = tried first.
        // usort is stable, so equal specificity keeps registration order.
        usort($this->routes, static fn (array $a, array $b): int => $b['literals'] <=> $a['literals']);

        return $this;
    }

    /** @return list<array{method: string, path: string}> */
    public function routes(): array
    {
        $out = array_map(static fn (array $r): array => ['method' => $r['method'], 'path' => $r['template']], $this->routes);
        usort($out, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return $out;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /** The function entrypoint: `return $app->handler();` */
    public function handler(): \Closure
    {
        return fn (mixed $context): mixed => $this->handle($context);
    }

    public function handle(mixed $context): mixed
    {
        $req = \is_object($context) ? ($context->req ?? null) : null;
        $res = \is_object($context) ? ($context->res ?? null) : null;
        $method = strtoupper((string) ($req->method ?? 'GET'));
        $rawPath = (string) ($req->path ?? '/');
        $path = rtrim(explode('?', $rawPath, 2)[0], '/') ?: '/';
        $headers = \Revenexx\AppSdk\Adapter\RuntimeAdapter::headers($context);
        $log = static function (string $m) use ($context): void {
            if (\is_object($context) && method_exists($context, 'log')) {
                $context->log($m);
            }
        };
        $error = static function (string $m) use ($context): void {
            if (\is_object($context) && method_exists($context, 'error')) {
                $context->error($m);
            }
        };
        $respond = static fn (mixed $data, int $status = 200): mixed => self::respond($res, $data, $status);

        $token = $headers['x-revenexx-context'] ?? '';
        $tenant = ($headers['x-revenexx-tenant'] ?? '') ?: (self::claims($token)['tenant_id'] ?? null);
        $tenant = \is_string($tenant) && $tenant !== '' ? $tenant : null;
        $log(($this->options['name'] ?? 'app') . " {$method} {$path} — tenant=" . ($tenant ?? 'none'));

        try {
            if (($this->options['health'] ?? true) && $path === '/' && $method === 'GET') {
                $env = fn (string $k): ?string => self::env($this->options, $k);

                return $respond([
                    'app' => [
                        'id' => $env('REVENEXX_APP_ID'),
                        'name' => $this->options['name'] ?? $env('REVENEXX_APP_NAME'),
                        'deployment' => $env('REVENEXX_APP_DEPLOYMENT'),
                        'runtime' => ($env('REVENEXX_APP_RUNTIME_NAME') ?? '') . '-' . ($env('REVENEXX_APP_RUNTIME_VERSION') ?? ''),
                    ],
                    'status' => 'ok',
                    'routes' => $this->routes(),
                    'timestamp' => gmdate('c'),
                    ...(isset($this->options['healthExtra']) ? ($this->options['healthExtra'])() : []),
                ]);
            }

            $reqSegs = self::split($path);
            $matched = null;
            $pathExists = false;
            foreach ($this->routes as $route) {
                $params = self::match($route['segments'], $reqSegs);
                if ($params === null) {
                    continue;
                }
                $pathExists = true;
                if ($route['method'] === $method) {
                    $matched = [$route, $params];
                    break;
                }
            }
            if ($matched === null) {
                return $pathExists
                    ? $respond(HttpError::refusalBody("method {$method} not allowed for {$path}", 'method_not_allowed'), 405)
                    : $respond([...HttpError::refusalBody("no route for {$method} {$path}", 'no_route'), 'routes' => $this->routes()], 404);
            }

            // Gateway-extracted params win over our own match, for safety.
            $gateway = json_decode($headers['x-capability-params'] ?? '', true);
            $c = new RouteContext(
                ctx: $context,
                method: $method,
                path: $path,
                params: [...$matched[1], ...(\is_array($gateway) ? array_map('strval', $gateway) : [])],
                query: self::query($req, $rawPath),
                body: self::body($req),
                headers: $headers,
                tenant: $tenant,
                contextToken: $token,
                actor: Actor::fromHeaders(static fn (string $n): string => $headers[$n] ?? ''),
                settingsReader: $this->settings,
                respond: \Closure::fromCallable($respond),
                logger: \Closure::fromCallable($log),
            );

            return ($matched[0]['handler'])($c);
        } catch (HttpError $e) {
            // The diagnosis stays on this side of the wire: logged, never answered.
            if ($e->serverDetail !== null) {
                $detail = \is_string($e->serverDetail) ? $e->serverDetail : (string) json_encode($e->serverDetail);
                $error("{$method} {$path} — {$e->status} {$e->getMessage()}: {$detail}");
            }

            return $respond($e->body(), $e->status);
        } catch (\Throwable $e) {
            // Anything that is not an HttpError is a fault, not an answer. Another
            // App's refusal nobody caught is logged as what the far side said.
            $upstream = \Revenexx\AppSdk\Refusal::of($e);
            $why = $upstream !== null
                ? trim(preg_replace('/\s+/', ' ', 'refused by ' . ($upstream->app ?? 'another app') . ": {$upstream->status} " . ($upstream->code ?? '') . " {$upstream->message}") ?? '')
                : $e->getMessage();
            $error("unhandled error on {$method} {$path}: {$why}");

            return $respond(HttpError::refusalBody('internal error', 'internal_error'), 500);
        }
    }

    /**
     * Write JSON through the runtime's `send()`: its `json()` encodes with
     * JSON_FORCE_OBJECT, which would turn every list into an object.
     */
    private static function respond(mixed $res, mixed $data, int $status): mixed
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (\is_object($res) && method_exists($res, 'send')) {
            return $res->send($json, $status, ['content-type' => 'application/json']);
        }

        return ['body' => $json, 'statusCode' => $status, 'headers' => ['content-type' => 'application/json']];
    }

    /** @return list<string> */
    private static function split(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param list<array{lit?: string, param?: string}> $segments
     * @param list<string>                              $reqSegs
     * @return array<string, string>|null
     */
    private static function match(array $segments, array $reqSegs): ?array
    {
        if (\count($segments) !== \count($reqSegs)) {
            return null;
        }
        $params = [];
        foreach ($segments as $i => $s) {
            if (isset($s['param'])) {
                $params[$s['param']] = rawurldecode($reqSegs[$i]);
            } elseif ($s['lit'] !== $reqSegs[$i]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * The query, percent-decoded exactly once, `+` as a space. The raw query
     * string is the source: the runtime's own `$req->query` hands values over
     * undecoded, so `?name=ACME%20GmbH` would match nothing. It stays the
     * fallback (decoded here) for a caller that sets it without a query string.
     *
     * @return array<string, string>
     */
    private static function query(mixed $req, string $rawPath): array
    {
        $qs = str_contains($rawPath, '?') ? explode('?', $rawPath, 2)[1] : (string) ($req->queryString ?? '');
        $out = [];
        if ($qs !== '') {
            foreach (explode('&', $qs) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $out[urldecode($k)] = urldecode($v);
            }

            return $out;
        }
        foreach ((array) ($req->query ?? []) as $k => $v) {
            if ($v !== null) {
                $out[urldecode((string) $k)] = urldecode((string) $v);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function body(mixed $req): array
    {
        if (! \is_object($req)) {
            return [];
        }
        $text = $req->bodyText ?? null;
        if (! \is_string($text) || $text === '') {
            $text = $req->bodyBinary ?? ($req->bodyRaw ?? null);
        }
        if (\is_string($text) && $text !== '') {
            $decoded = json_decode($text, true);

            return \is_array($decoded) ? $decoded : [];
        }
        $body = $req->body ?? null;

        return \is_array($body) ? $body : [];
    }

    /** @return array<string, mixed> */
    private static function claims(string $token): array
    {
        $parts = explode('.', $token);
        if (\count($parts) < 2) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $decoded = $json !== false ? json_decode($json, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $options */
    private static function env(array $options, string $key): ?string
    {
        $value = isset($options['env']) ? ($options['env'][$key] ?? null) : getenv($key);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
