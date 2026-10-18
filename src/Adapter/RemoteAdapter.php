<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Adapter;

use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\CountsRows;
use Revenexx\AppSdk\EmitsEvents;
use Revenexx\AppSdk\EmitTarget;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\PostgrestError;
use Revenexx\AppSdk\Query;
use Revenexx\AppSdk\UpdatesConditionally;

/**
 * PostgREST-over-HTTP adapter (production data plane / dev tenant). The token is
 * the brokered per-tenant identity, presented to PostgREST as a
 * Bearer JWT; the tenant header rides alongside. The HTTP transport is
 * injectable so it can be unit-tested without a live PostgREST.
 *
 * A failed request is an {@see HttpError} mapped by {@see PostgrestError}: the
 * status it deserves and a message naming nothing internal.
 *
 * The transport answers `['status' => int, 'body' => string]` and may add
 * `'headers' => ['content-range' => '0-9/247', …]` (lower-case names), which
 * `page()` reads the total from.
 *
 * @phpstan-type Transport callable(string, string, array<int, string>, ?string): array{status: int, body: string, headers?: array<string, string>}
 */
final class RemoteAdapter implements Adapter, CountsRows, EmitsEvents, UpdatesConditionally
{
    /** @var callable(string, string, array<int, string>, ?string): array{status: int, body: string, headers?: array<string, string>} */
    private $transport;

    public function __construct(
        private readonly string $endpoint,
        private readonly ?string $token = null,
        private readonly ?string $tenant = null,
        ?callable $transport = null,
        private readonly ?string $market = null,
        private readonly string $kind = 'remote',
        private readonly ?string $audit = null,
        private readonly ?string $auditNote = null,
    ) {
        if ($endpoint === '') {
            throw new \InvalidArgumentException('remote adapter: endpoint is required');
        }
        $this->transport = $transport ?? self::curlTransport();
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function list(Entity $entity, array $query = []): array
    {
        $qs = Query::toParams($query);
        $answer = $this->send('GET', "/{$entity->table}" . ($qs !== '' ? "?{$qs}" : ''));
        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            // An offset past the end of the result set is a legitimate request.
            if (PostgrestError::rangeNotSatisfiable($answer['status'], $answer['body']) !== null) {
                return [];
            }
            throw PostgrestError::map($answer['status'], $answer['body'], 'GET', $entity->table);
        }

        return self::decode($answer['body']) ?? [];
    }

    /**
     * One request: the page in the body, the total in `Content-Range`
     * (`0-9/247` or `*\/247`) when asked for an exact count.
     */
    public function page(Entity $entity, array $query = []): array
    {
        $qs = Query::toParams($query);
        $answer = $this->send('GET', "/{$entity->table}" . ($qs !== '' ? "?{$qs}" : ''), null, 'count=exact');
        $range = $answer['headers']['content-range'] ?? '';
        $total = preg_match('#/(\d+)$#', $range, $m) ? (int) $m[1] : null;
        $limit = isset($query['limit']) ? (int) $query['limit'] : null;
        $offset = isset($query['offset']) ? (int) $query['offset'] : null;

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            $overflow = PostgrestError::rangeNotSatisfiable($answer['status'], $answer['body']);
            if ($overflow !== null) {
                return ['items' => [], 'total' => $total ?? $overflow, 'limit' => $limit, 'offset' => $offset];
            }
            throw PostgrestError::map($answer['status'], $answer['body'], 'GET', $entity->table);
        }
        $items = self::decode($answer['body']) ?? [];

        return ['items' => $items, 'total' => $total ?? \count($items), 'limit' => $limit, 'offset' => $offset];
    }

    public function get(Entity $entity, int|string $id): ?array
    {
        $rows = $this->request('GET', "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id) . '&limit=1', null, null, $entity->table);

        return $rows[0] ?? null;
    }

    public function create(Entity $entity, array $data): array
    {
        $rows = $this->request('POST', "/{$entity->table}", $data, 'return=representation', $entity->table);

        // An insert that reports success but returns no row is not readable back
        // (an RLS policy that permits INSERT but not SELECT): a described 5xx.
        return $this->oneRow($rows)
            ?? throw new HttpError(500, 'the record was created but could not be read back', null, 'empty_representation');
    }

    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $rows = $this->request(
            'PATCH',
            "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id),
            $patch,
            'return=representation',
            $entity->table,
        );

        // PostgREST reports a write that touched no row as `200 []`. Returning
        // that empty array as if it were the updated record hides a missing row
        // behind a success; say so instead.
        return $this->oneRow($rows) ?? throw HttpError::notFound();
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
            $entity->table,
        );

        return $this->oneRow($rows);
    }

    public function remove(Entity $entity, int|string $id): void
    {
        $this->request('DELETE', "/{$entity->table}?{$entity->pk}=eq." . rawurlencode((string) $id), null, null, $entity->table);
    }

    /**
     * Fire one of the App's own declared events through the routine the platform
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
            throw new HttpError(502, 'the event service returned no event id', null, 'emit_no_id', [
                'fn' => $target->fn,
                'event' => $target->name,
            ]);
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
     * The record-history note as it may be forwarded: one line of printable
     * ASCII (a URI-encoded JSON object is exactly that) of at most 8192 bytes —
     * Baseline's cap on the header as received — else nothing. Its content is
     * not read here; Baseline parses it and ignores what it cannot read, so a
     * missing or bad note never fails a call. It never decides who is acting.
     */
    public static function auditNote(?string $value): string
    {
        return $value !== null && preg_match('/^[\x20-\x7e]{1,8192}$/D', $value) === 1 ? $value : '';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<int, array<string, mixed>>|null
     */
    private function request(string $method, string $path, ?array $body = null, ?string $prefer = null, ?string $table = null): ?array
    {
        $answer = $this->send($method, $path, $body, $prefer);
        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            throw PostgrestError::map($answer['status'], $answer['body'], $method, $table);
        }

        return self::decode($answer['body']);
    }

    /**
     * The audit envelope as it may be forwarded: one line of visible ASCII
     * (`v1.<kid>.<payload>.<sig>` is base64url and dots), else nothing. Its
     * content is not read here; Baseline verifies it and ignores what does not
     * verify, so a missing or bad envelope never fails a call.
     */
    public static function auditEnvelope(?string $value): string
    {
        return $value !== null && preg_match('/^[\x21-\x7e]{1,4096}$/D', $value) === 1 ? $value : '';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function send(string $method, string $path, ?array $body = null, ?string $prefer = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($this->tenant !== null && $this->tenant !== '') {
            $headers[] = 'X-Revenexx-Tenant: ' . $this->tenant;
        }
        // The active market, forwarded to the data layer like the JavaScript SDK does.
        if ($this->market !== null && $this->market !== '') {
            $headers[] = 'X-Revenexx-Market: ' . $this->market;
        }
        // The request's record-history envelope, passed on unchanged and never read here.
        $audit = self::auditEnvelope($this->audit);
        if ($audit !== '') {
            $headers[] = 'X-Revenexx-Audit: ' . $audit;
        }
        // What the change is and why, passed on unchanged and never read here.
        $note = self::auditNote($this->auditNote);
        if ($note !== '') {
            $headers[] = 'X-Revenexx-Audit-Note: ' . $note;
        }
        if ($prefer !== null) {
            $headers[] = 'Prefer: ' . $prefer;
        }

        $payload = $body !== null ? (string) json_encode($body) : '';
        $answer = ($this->transport)(
            $method,
            rtrim($this->endpoint, '/') . $path,
            $headers,
            $payload === '' ? null : $payload,
        );

        return [
            'status' => (int) $answer['status'],
            'body' => (string) $answer['body'],
            'headers' => array_change_key_case($answer['headers'] ?? []),
        ];
    }

    /** @return array<int|string, mixed>|null */
    private static function decode(string $body): ?array
    {
        $decoded = $body !== '' ? json_decode($body, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return callable(string, string, array<int, string>, ?string): array{status: int, body: string, headers: array<string, string>}
     */
    private static function curlTransport(): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (\count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return \strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $responseBody = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders];
        };
    }
}
