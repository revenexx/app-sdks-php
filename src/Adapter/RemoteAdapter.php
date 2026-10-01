<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Adapter;

use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\EmitsEvents;
use Revenexx\AppSdk\EmitTarget;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;
use Revenexx\AppSdk\UpdatesConditionally;

/**
 * PostgREST-over-HTTP adapter (production data plane / dev tenant). The token is
 * the brokered per-tenant identity (ADR-0057), presented to PostgREST as a
 * Bearer JWT; the tenant header rides alongside. The HTTP transport is
 * injectable so it can be unit-tested without a live PostgREST.
 *
 * @phpstan-type Transport callable(string, string, array<int, string>, ?string): array{status: int, body: string}
 */
final class RemoteAdapter implements Adapter, EmitsEvents, UpdatesConditionally
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

        return $this->oneRow($rows)
            ?? throw new \RuntimeException("app-sdk: {$entity->name} was created but could not be read back");
    }

    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $rows = $this->request(
            'PATCH',
            "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id),
            $patch,
            'return=representation',
        );

        // PostgREST reports a write that touched no row as `200 []`. Returning
        // that empty array as if it were the updated record hides a missing row
        // behind a success; say so instead.
        return $this->oneRow($rows)
            ?? throw new \RuntimeException("app-sdk: {$entity->name}/{$id} not found");
    }

    /**
     * One PATCH with the condition ANDed onto the pk filter: Postgres re-checks
     * the WHERE on the locked row, so of two racing writers exactly one
     * matches. No prior read — that would reopen the race. `200 []` is the
     * answer here, not a fault: no row matched.
     */
    public function updateIf(Entity $entity, int|string $id, array $where, array $patch): ?array
    {
        $rows = $this->request(
            'PATCH',
            "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id) . '&' . Query::toParams(['where' => $where]),
            $patch,
            'return=representation',
        );

        return $this->oneRow($rows);
    }

    public function remove(Entity $entity, int|string $id): void
    {
        $this->request('DELETE', "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id));
    }

    /**
     * Fire one of the App's own declared events through the routine Baseline
     * generated for it: `POST /rpc/{vendor}__{app}__emit`.
     *
     * Nothing about WHICH app is emitting travels in this request. The routine
     * is per app, its topic prefix and its allowlist are literals, and only the
     * app's Postgres role may execute it — so the same token that reaches the
     * app's tables is the whole credential.
     *
     * The body keys are the routine's parameter names, not ours: `topicId`
     * becomes `topic_id`, and `id` becomes `event_id` because a parameter named
     * `id` would shadow the outbox column in the routine's ON CONFLICT clause.
     *
     * @param array<string, mixed> $data
     * @param array{topicId?: ?string, metadata?: array<string, mixed>, id?: ?string} $options
     * @return array{id: string, topic: string}
     */
    public function emit(EmitTarget $target, array $data = [], array $options = []): array
    {
        $answer = $this->request('POST', "/rpc/{$target->fn}", [
            'event' => $target->name,
            'data' => (object) $data,
            'topic_id' => $options['topicId'] ?? null,
            'metadata' => (object) ($options['metadata'] ?? []),
            'event_id' => $options['id'] ?? null,
        ]) ?? [];

        $id = \is_string($answer['id'] ?? null) ? $answer['id'] : null;
        $topic = \is_string($answer['topic'] ?? null) ? $answer['topic'] : null;

        // The routine always returns both, so an answer without them means the
        // request reached something other than the routine we asked for.
        // Failing here beats handing the caller an event id of null.
        if ($id === null || $topic === null) {
            throw new \RuntimeException(
                "app-sdk: {$target->fn} returned no event id for '{$target->name}'",
            );
        }

        return ['id' => $id, 'topic' => $topic];
    }

    /**
     * The single record out of a `return=representation` response: the first
     * element of a list, or the body itself when PostgREST returned one object.
     * Null when the write returned no representation at all.
     *
     * @param  array<int|string, mixed>|null $rows
     * @return array<string, mixed>|null
     */
    private function oneRow(?array $rows): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        /** @var array<string, mixed> */
        return \is_array($rows[0] ?? null) ? $rows[0] : $rows;
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
