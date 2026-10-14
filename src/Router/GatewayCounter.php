<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\Settings;

/**
 * Count another App's records through the gateway, for a blocker that crosses
 * an App boundary ("open orders of this organization") — the counterpart of
 * `createGatewayCounter` in the JavaScript SDK. Apps never read each other's
 * tables; the owning App's list route answers, and its `page.total` is the
 * count.
 *
 *   $refs = new GatewayCounter();
 *   'blockers' => fn (array $org, mixed $c) => [
 *       $refs->blocker($c, ['path' => '/v1/orders/open_orders', 'where' => ['organization_id' => $org['id']], 'reason' => 'open orders']),
 *   ],
 *
 * A count the gateway does not give — an error, a timeout, a route that does
 * not report `total` — is null, and `blocker` turns null into an
 * `unverifiable` blocker. Not knowing is never a pass.
 *
 * Options: `endpoint` (default REVENEXX_GATEWAY_ENDPOINT or the platform
 * gateway), `timeoutMs` (5000), `env`, `transport` (tests).
 */
final class GatewayCounter
{
    private string $endpoint;
    private int $timeoutMs;
    /** @var callable(string, array<int, string>, int): array{status: int, body: string} */
    private $transport;

    /** @param array{endpoint?: string, timeoutMs?: int, env?: array<string, string|false|null>, transport?: callable} $options */
    public function __construct(array $options = [])
    {
        $read = Trash::envReader($options['env'] ?? null);
        $this->endpoint = rtrim((string) (($options['endpoint'] ?? '') ?: ($read('REVENEXX_GATEWAY_ENDPOINT') ?: Settings::DEFAULT_GATEWAY_ENDPOINT)), '/');
        $this->timeoutMs = (int) ($options['timeoutMs'] ?? Trash::DEFAULT_TIMEOUT_MS);
        $this->transport = $options['transport'] ?? Trash::curlTransport();
    }

    /**
     * The owning App's `page.total` for `$where`, or null when it gave none.
     *
     * @param array<string, string|int|float|bool> $where
     */
    public function count(mixed $caller, string $path, array $where = []): ?int
    {
        $qs = [];
        foreach ($where as $k => $v) {
            $qs[$k] = \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        $qs['limit'] = '1';
        [$tenant, $token, $appContext] = Trash::identity($caller);
        $headers = ['Accept: application/json'];
        if ($tenant !== '') {
            $headers[] = 'X-Revenexx-Tenant: ' . $tenant;
        }
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($appContext !== '') {
            $headers[] = 'x-revenexx-app-context: ' . $appContext;
        }

        try {
            $sep = str_contains($path, '?') ? '&' : '?';
            $answer = ($this->transport)($this->endpoint . $path . $sep . http_build_query($qs, '', '&', PHP_QUERY_RFC3986), $headers, $this->timeoutMs);
            $status = (int) ($answer['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                return null;
            }
            $body = json_decode((string) ($answer['body'] ?? ''), true);
            $total = \is_array($body) && \is_array($body['page'] ?? null) ? ($body['page']['total'] ?? null) : null;

            return \is_int($total) ? $total : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A blocker naming the count, null for none, or an `unverifiable` blocker
     * when there was no count.
     *
     * @param array{path: string, where?: array<string, string|int|float|bool>, reason: string} $check
     * @return array{reason: string, count?: int, code: string}|null
     */
    public function blocker(mixed $caller, array $check): ?array
    {
        $n = $this->count($caller, $check['path'], $check['where'] ?? []);
        if ($n === null) {
            return ['reason' => "{$check['reason']} — could not be checked", 'code' => 'unverifiable'];
        }
        if ($n === 0) {
            return null;
        }

        return ['reason' => "{$n} {$check['reason']}", 'count' => $n, 'code' => 'referenced'];
    }
}
