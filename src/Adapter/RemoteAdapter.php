<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Adapter;

use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;

/**
 * PostgREST-over-HTTP adapter (production data plane / dev tenant). The token is
 * the brokered per-tenant identity (ADR-0057), presented to PostgREST as a
 * Bearer JWT; the tenant header rides alongside. The HTTP transport is
 * injectable so it can be unit-tested without a live PostgREST.
 *
 * @phpstan-type Transport callable(string, string, array<int, string>, ?string): array{status: int, body: string}
 */
final class RemoteAdapter implements Adapter
{
    /** @var callable(string, string, array<int, string>, ?string): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $token = null,
        private readonly ?string $tenant = null,
        ?callable $transport = null,
    ) {
        if ($endpoint === '') {
            throw new \InvalidArgumentException('remote adapter: endpoint is required');
        }
        $this->transport = $transport ?? self::curlTransport();
    }

    public function kind(): string
    {
        return 'remote';
    }

    public function list(Entity $entity, array $query = []): array
    {
        $qs = Query::toParams($query);

        return $this->request('GET', "/{$entity->table}" . ($qs !== '' ? "?{$qs}" : '')) ?? [];
    }

    public function get(Entity $entity, int|string $id): ?array
    {
        $rows = $this->request('GET', "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id) . '&limit=1');

        return $rows[0] ?? null;
    }

    public function create(Entity $entity, array $data): array
    {
        $rows = $this->request('POST', "/{$entity->table}", $data, 'return=representation');

        return $rows[0] ?? $rows ?? [];
    }

    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $rows = $this->request(
            'PATCH',
            "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id),
            $patch,
            'return=representation',
        );

        return $rows[0] ?? $rows ?? [];
    }

    public function remove(Entity $entity, int|string $id): void
    {
        $this->request('DELETE', "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<int, array<string, mixed>>|null
     */
    private function request(string $method, string $path, ?array $body = null, ?string $prefer = null): ?array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($this->tenant !== null && $this->tenant !== '') {
            $headers[] = 'X-Revenexx-Tenant: ' . $this->tenant;
        }
        if ($prefer !== null) {
            $headers[] = 'Prefer: ' . $prefer;
        }

        $payload = $body !== null ? (string) json_encode($body) : '';
        ['status' => $status, 'body' => $responseBody] = ($this->transport)(
            $method,
            rtrim($this->endpoint, '/') . $path,
            $headers,
            $payload === '' ? null : $payload,
        );

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("PostgREST {$status}: {$responseBody}");
        }

        $decoded = $responseBody !== '' ? json_decode($responseBody, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return callable(string, string, array<int, string>, ?string): array{status: int, body: string}
     */
    private static function curlTransport(): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $responseBody = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['status' => $status, 'body' => $responseBody];
        };
    }
}
