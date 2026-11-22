<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\Settings;

/**
 * What a handler gets: the request, parsed, and the answer it can give — the
 * PHP counterpart of the JavaScript SDK's `RouteContext`.
 */
final class RouteContext
{
    /**
     * @param array<string, string> $params  path parameters, the gateway's over the router's
     * @param array<string, string> $query   decoded exactly once
     * @param array<string, mixed>  $body    the parsed JSON body (empty when none)
     * @param array<string, string> $headers lower-cased
     */
    public function __construct(
        public readonly mixed $ctx,
        public readonly string $method,
        public readonly string $path,
        public readonly array $params,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly ?string $tenant,
        public readonly string $contextToken,
        public readonly Actor $actor,
        private readonly Settings $settingsReader,
        private readonly \Closure $respond,
        private readonly \Closure $logger,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Answer with JSON. Use this, not `$context->res->json()`: the runtime's
     * own `json()` encodes with JSON_FORCE_OBJECT, which turns every list into
     * an object (`{"0":…}`).
     */
    public function json(mixed $data, int $status = 200): mixed
    {
        return ($this->respond)($data, $status);
    }

    public function log(string $message): void
    {
        ($this->logger)($message);
    }

    /**
     * The tenant's settings for this App, resolved for the request's market.
     *
     * @return array<string, mixed>
     */
    public function settings(?string $market = null): array
    {
        return $this->settingsReader->get($this, $market);
    }

    /** @return array<string, mixed> */
    public function settingsSnapshot(?string $market = null): array
    {
        return $this->settingsReader->load($this, $market);
    }

    /**
     * Store this App's OWN tenant-level settings for the request's tenant —
     * `ifAbsent: true` for defaults, so a merchant's choice is never
     * overwritten. Never throws; see Settings::set().
     *
     * @param array<string, mixed> $values
     * @return array{ok: bool, status: int, written: list<string>, kept: list<string>, ignored: list<string>, error?: string}
     */
    public function setSettings(array $values, bool $ifAbsent = false): array
    {
        return $this->settingsReader->set($this, $values, $ifAbsent);
    }
}
