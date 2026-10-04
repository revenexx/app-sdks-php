<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Adapter;

/**
 * Production adapter: a {@see RemoteAdapter} configured from the function's own
 * request (ADR-0057) — the mirror of packages/sdk/src/adapters/runtime.ts.
 *
 * Token = the brokered identity forwarded as `x-revenexx-context`; tenant =
 * `x-revenexx-tenant`; market = `x-revenexx-market`; endpoint =
 * `REVENEXX_DATA_ENDPOINT`, falling back to the platform data plane. A deployed
 * App needs no configuration:
 *
 *   return function ($context) {
 *       $db = Db::create('runtime', ['context' => $context]);
 *       …
 *   };
 */
final class RuntimeAdapter
{
    /** The platform data plane. Override with REVENEXX_DATA_ENDPOINT. */
    public const DEFAULT_DATA_ENDPOINT = 'https://apps.api.revenexx.com';

    /**
     * @param array{context?: mixed, env?: array<string, string|false|null>, transport?: callable} $config
     */
    public static function create(array $config = []): RemoteAdapter
    {
        $env = $config['env'] ?? null;
        $read = static function (string $name) use ($env): string {
            $value = \is_array($env) ? ($env[$name] ?? null) : getenv($name);

            return \is_string($value) ? $value : '';
        };
        $headers = self::headers($config['context'] ?? null);
        $header = static fn (string $name): string => (string) ($headers[$name] ?? '');

        return new RemoteAdapter(
            endpoint: $read('REVENEXX_DATA_ENDPOINT') ?: self::DEFAULT_DATA_ENDPOINT,
            token: $header('x-revenexx-context') ?: $read('REVENEXX_CONTEXT'),
            tenant: $header('x-revenexx-tenant') ?: $read('REVENEXX_TENANT'),
            transport: $config['transport'] ?? null,
            market: $header('x-revenexx-market') ?: $read('REVENEXX_MARKET'),
            kind: 'runtime',
        );
    }

    /**
     * Lower-cased request headers from an open-runtimes context (`$context->req->headers`),
     * an array shaped the same way, or a bare header array.
     *
     * @return array<string, string>
     */
    public static function headers(mixed $context): array
    {
        $req = \is_object($context) ? ($context->req ?? null) : (\is_array($context) ? ($context['req'] ?? null) : null);
        $headers = \is_object($req) ? ($req->headers ?? []) : (\is_array($req) ? ($req['headers'] ?? []) : []);
        if ($req === null && \is_array($context)) {
            $headers = $context;
        }
        $out = [];
        foreach ((array) $headers as $name => $value) {
            if (\is_string($name) && (\is_string($value) || \is_numeric($value))) {
                $out[strtolower($name)] = (string) $value;
            }
        }

        return $out;
    }
}
