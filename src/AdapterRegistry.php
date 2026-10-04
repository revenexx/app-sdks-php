<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

use Revenexx\AppSdk\Adapter\MockAdapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\Adapter\RuntimeAdapter;

/**
 * Adapter registry. Built-ins (mock | remote | runtime) register lazily; add a new
 * backend by calling `AdapterRegistry::register('my-kind', fn ($config) => …)`
 * before `Db::create()` — no changes to the client or generated code.
 */
final class AdapterRegistry
{
    /** @var array<string, callable(array<string, mixed>): Adapter> */
    private static array $factories = [];

    private static bool $booted = false;

    /** @param callable(array<string, mixed>): Adapter $factory */
    public static function register(string $kind, callable $factory): void
    {
        self::$factories[$kind] = $factory;
    }

    /** @return string[] */
    public static function kinds(): array
    {
        self::boot();

        return array_keys(self::$factories);
    }

    /** @param array<string, mixed> $config */
    public static function create(string $kind, array $config = []): Adapter
    {
        self::boot();
        $factory = self::$factories[$kind] ?? null;
        if ($factory === null) {
            $known = implode(' | ', array_keys(self::$factories)) ?: 'none';
            throw new \InvalidArgumentException("app-sdk: unknown adapter '{$kind}' ({$known})");
        }

        return $factory($config);
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::$factories['mock'] ??= static fn (array $c): Adapter => new MockAdapter($c['seed'] ?? []);
        self::$factories['remote'] ??= static fn (array $c): Adapter => new RemoteAdapter(
            (string) ($c['endpoint'] ?? ''),
            $c['token'] ?? null,
            $c['tenant'] ?? null,
            $c['transport'] ?? null,
            $c['market'] ?? null,
        );
        // In-function (production): configured from the request it serves.
        self::$factories['runtime'] ??= static fn (array $c): Adapter => RuntimeAdapter::create($c);
    }
}
