<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\Adapter\RuntimeAdapter;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Settings;

/**
 * The trash for one entity, in one call — the counterpart of `mountTrash` in
 * the JavaScript SDK, with the same rules (PE-1022):
 *
 *   $refs = new GatewayCounter();
 *   $trash = Trash::mount($app, $db, $db->entity('organizations'), [
 *       'path' => '/organizations',
 *       'children' => [['name' => 'contacts', 'entity' => $db->entity('contacts'), 'column' => 'organization_id']],
 *       'guard' => fn (array $org) => $org['is_default'] ? ['reason' => 'the default organization stays'] : null,
 *       'blockers' => fn (array $org, mixed $c) => [
 *           $refs->blocker($c, ['path' => '/v1/orders/open_orders', 'where' => ['organization_id' => $org['id']], 'reason' => 'open orders']),
 *       ],
 *   ]);
 *
 *   // the scheduled job (ADR-0103), once per tenant:
 *   $trash->purge($caller);
 *
 *   DELETE <path>/{id}          live row    → into the trash, with its children
 *                               trashed row → deleted for good, through `blockers`
 *   POST   <path>/{id}/restore  back out of the trash, with the children it took
 *
 * The entity needs `trash: true` in schema.json, which gives it `deleted_at`,
 * `deleted_by`, `deleted_by_id`, `trash_batch` and `purge_blocked_reason`.
 *
 * Two rules this class exists to keep: the final delete has ONE code path (the
 * second DELETE and the purge both go through `deleteForGood`'s checks), and a
 * blocker that cannot be checked blocks — a check that throws, or a count the
 * gateway would not answer, keeps the row.
 *
 * A caller is a `RouteContext`, an open-runtimes context, or a header array
 * (`['x-revenexx-tenant' => …, 'x-revenexx-context' => …]`) for the purge job.
 * A blocker is `['reason' => string, 'count'?, 'code'?, 'details'?]`; a check
 * returns null, one blocker, or a list of them.
 *
 * Options: `path` (required), `idParam` (`id`), `children` (`name`, `entity`,
 * `column`), `guard`, `blockers`, `canDeleteForGood` (callable(caller): bool),
 * `parent` (`entity`, `column`), `deleteRow` (callable(row, caller)),
 * `purgeReasonColumn` (default `purge_blocked_reason`, `false` for none),
 * `events` (`trashed` / `restored` / `purged` names, optional `emit` callable;
 * default the client's events), `retention` (see `readRetention`), `now`.
 */
final class Trash
{
    /** The column whose value says "in the trash". */
    public const TRASH_COLUMN = 'deleted_at';

    /** The column `trash: true` gives an entity for why the purge left a row in the trash. */
    public const PURGE_REASON_COLUMN = 'purge_blocked_reason';

    /** Trash retention when the tenant has none set (ADR-0114). */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** The gateway route the tenant's retention is read from. */
    public const AUDIT_SETTINGS_PATH = '/v1/audit/settings';

    public const DEFAULT_TIMEOUT_MS = 5_000;

    private const PAGE = 200;

    private string $idParam;
    /** @var list<array{name: string, entity: Entity, column: string}> */
    private array $children;
    private ?string $reasonColumn;
    /** @var callable(): \DateTimeImmutable */
    private $now;

    /** @param array<string, mixed> $options */
    private function __construct(
        private readonly Client $client,
        private readonly Entity $entity,
        private readonly array $options,
    ) {
        $this->idParam = (string) ($options['idParam'] ?? 'id');
        $this->children = array_values($options['children'] ?? []);
        $column = $options['purgeReasonColumn'] ?? self::PURGE_REASON_COLUMN;
        $this->reasonColumn = $column === false || $column === null || $column === '' ? null : (string) $column;
        $this->now = $options['now'] ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Mount the trash routes for one entity and return the handle the purge
     * job (and any hand-written route) uses.
     *
     * @param array<string, mixed> $options
     */
    public static function mount(App $app, Client $client, Entity $entity, array $options): self
    {
        $trash = new self($client, $entity, $options);
        $base = rtrim((string) $options['path'], '/');
        $idParam = $trash->idParam;

        $app->delete("{$base}/{{$idParam}}", static function (RouteContext $c) use ($trash, $idParam): mixed {
            $id = $c->params[$idParam] ?? '';
            $row = $trash->load($id);
            if (self::isTrashed($row)) {
                $out = $trash->deleteForGood($id, $c);

                return $c->json(['deleted' => true, ...$out, 'took' => (object) $out['took']]);
            }
            $out = $trash->trash($id, $c);

            return $c->json(['trashed' => true, ...$out, 'took' => (object) $out['took']]);
        });

        $app->post("{$base}/{{$idParam}}/restore", static function (RouteContext $c) use ($trash, $idParam): mixed {
            $out = $trash->restore($c->params[$idParam] ?? '', $c);

            return $c->json(['restored' => true, ...$out, 'took' => (object) $out['took']]);
        });

        return $trash;
    }

    /**
     * Put a row into the trash, with its live children as one group.
     *
     * @return array{id: string, batch: string, took: array<string, int>}
     */
    public function trash(string $id, mixed $caller): array
    {
        $row = $this->load($id);
        if (self::isTrashed($row)) {
            throw new HttpError(409, 'this record is already in the trash', null, 'already_in_trash');
        }
        $blockers = self::runCheck($this->options['guard'] ?? null, $row, $caller);
        if ($blockers !== []) {
            throw new HttpError(409, 'cannot go into the trash: ' . self::reasons($blockers), ['blockers' => $blockers], 'trash_blocked');
        }

        $actor = $caller instanceof RouteContext ? $caller->actor : null;
        $batch = self::uuid();
        $fields = [
            self::TRASH_COLUMN => self::iso(($this->now)()),
            'deleted_by' => $actor?->email ?? $actor?->user,
            'deleted_by_id' => $actor?->user ?? $actor?->contact,
            'trash_batch' => $batch,
        ];

        $this->client->update($this->entity, $id, $fields);
        $took = [];
        foreach ($this->children as $child) {
            // Only the live children join the group. One that was trashed on its
            // own earlier keeps its own batch, and stays in the trash on restore.
            $rows = $this->listAll($child['entity'], [$child['column'] => $id, self::TRASH_COLUMN => null]);
            foreach ($rows as $r) {
                $this->client->update($child['entity'], (string) $r['id'], $fields);
            }
            $took[$child['name']] = \count($rows);
        }

        $this->emit('trashed', ['id' => $id, 'batch' => $batch, 'took' => (object) $took], self::idOf($row, $id));

        return ['id' => $id, 'batch' => $batch, 'took' => $took];
    }

    /**
     * Take a row and the group it took back out of the trash.
     *
     * @return array{id: string, took: array<string, int>}
     */
    public function restore(string $id, mixed $caller): array
    {
        $row = $this->load($id);
        if (! self::isTrashed($row)) {
            throw new HttpError(409, 'this record is not in the trash', null, 'not_in_trash');
        }
        $parent = $this->options['parent'] ?? null;
        if ($parent !== null) {
            $parentId = $row[$parent['column']] ?? null;
            $parentRow = $parentId === null ? null : $this->client->get($parent['entity'], (string) $parentId);
            if ($parentRow !== null && self::isTrashed($parentRow)) {
                throw new HttpError(409, 'its parent is in the trash — restore the parent first', ['parent' => (string) $parentId], 'parent_in_trash');
            }
        }

        $fields = [self::TRASH_COLUMN => null, 'deleted_by' => null, 'deleted_by_id' => null, 'trash_batch' => null];
        $this->client->update($this->entity, $id, $this->reasonColumn !== null ? [...$fields, $this->reasonColumn => null] : $fields);
        $took = [];
        $batch = $row['trash_batch'] ?? null;
        foreach ($this->children as $child) {
            // Children never carry a purge reason: the purge only writes it onto
            // the row it was asked about.
            $rows = $batch === null ? [] : $this->listAll($child['entity'], [$child['column'] => $id, 'trash_batch' => $batch]);
            foreach ($rows as $r) {
                $this->client->update($child['entity'], (string) $r['id'], $fields);
            }
            $took[$child['name']] = \count($rows);
        }

        $this->emit('restored', ['id' => $id, 'took' => (object) $took], self::idOf($row, $id));

        return ['id' => $id, 'took' => $took];
    }

    /**
     * Delete a trashed row for good, through the blockers.
     *
     * @return array{id: string, took: array<string, int>}
     */
    public function deleteForGood(string $id, mixed $caller): array
    {
        $row = $this->load($id);
        if (! self::isTrashed($row)) {
            throw new HttpError(409, 'only a record in the trash can be deleted for good', null, 'not_in_trash');
        }
        $can = $this->options['canDeleteForGood'] ?? null;
        if ($can !== null && ! $can($caller)) {
            throw new HttpError(403, 'deleting for good needs its own permission', null, 'not_permitted');
        }
        $blockers = self::runCheck($this->options['blockers'] ?? null, $row, $caller);
        if ($blockers !== []) {
            throw new HttpError(409, 'cannot be deleted for good: ' . self::reasons($blockers), ['blockers' => $blockers], 'delete_blocked');
        }

        return $this->finalDelete($row, $id, $caller);
    }

    /**
     * The purge job: delete for good what is past the tenant's retention. Never
     * throws for a single row; skips the whole run when the retention cannot be
     * read.
     *
     * @param array{max?: int} $options
     * @return array{skipped: bool, reason?: string, retentionDays?: int, cutoff?: string, purged: list<string>, blocked: list<array{id: string, blockers: list<array<string, mixed>>}>}
     */
    public function purge(mixed $caller, array $options = []): array
    {
        try {
            $days = self::readRetention($caller, $this->options['retention'] ?? []);
        } catch (\Throwable $e) {
            return ['skipped' => true, 'reason' => $e->getMessage(), 'purged' => [], 'blocked' => []];
        }

        $cutoff = self::iso(($this->now)()->sub(new \DateInterval("P{$days}D")));
        // A live row is never due. PostgREST's `lt` already leaves out a null
        // `deleted_at`; the PHP mock compares null as smaller, so say it here.
        $due = array_values(array_filter(
            $this->listAll($this->entity, [self::TRASH_COLUMN => ['op' => 'lt', 'value' => $cutoff]]),
            self::isTrashed(...),
        ));
        $due = \array_slice($due, 0, (int) ($options['max'] ?? 500));

        $result = ['skipped' => false, 'retentionDays' => $days, 'cutoff' => $cutoff, 'purged' => [], 'blocked' => []];
        foreach ($due as $row) {
            $id = (string) $row['id'];
            // A purge is the second DELETE, run by the system: same blockers,
            // same children, same delete.
            $blockers = self::runCheck($this->options['blockers'] ?? null, $row, $caller);
            if ($blockers !== []) {
                $result['blocked'][] = ['id' => $id, 'blockers' => $blockers];
                $reason = self::reasons($blockers);
                // Written only when it changed: every write is an `*.updated`
                // event, and a nightly run must not repeat one for nothing.
                if ($this->reasonColumn !== null && ($row[$this->reasonColumn] ?? null) !== $reason) {
                    try {
                        $this->client->update($this->entity, $id, [$this->reasonColumn => $reason]);
                    } catch (\Throwable) {
                        // The reason is a courtesy; the row staying is what matters.
                    }
                }

                continue;
            }
            try {
                $this->finalDelete($row, $id, $caller);
                $result['purged'][] = $id;
            } catch (\Throwable $e) {
                $result['blocked'][] = ['id' => $id, 'blockers' => [['reason' => 'delete failed: ' . $e->getMessage(), 'code' => 'delete_failed']]];
            }
        }

        $this->emit('purged', [
            'retention_days' => $days,
            'cutoff' => $cutoff,
            'purged' => \count($result['purged']),
            'blocked' => \count($result['blocked']),
        ], null);

        return $result;
    }

    /**
     * The URL the retention is read from. First match wins: `url`,
     * REVENEXX_AUDIT_SETTINGS_URL, or `{endpoint}/v1/audit/settings` with
     * `endpoint` defaulting to REVENEXX_GATEWAY_ENDPOINT or the platform gateway.
     *
     * @param array{url?: string, endpoint?: string, env?: array<string, string|false|null>} $options
     */
    public static function auditSettingsUrl(array $options = []): string
    {
        $read = self::envReader($options['env'] ?? null);
        $url = ($options['url'] ?? '') ?: $read('REVENEXX_AUDIT_SETTINGS_URL');
        if ($url !== '') {
            return $url;
        }
        $endpoint = rtrim((string) (($options['endpoint'] ?? '') ?: ($read('REVENEXX_GATEWAY_ENDPOINT') ?: Settings::DEFAULT_GATEWAY_ENDPOINT)), '/');

        return $endpoint . self::AUDIT_SETTINGS_PATH;
    }

    /**
     * The tenant's trash retention in days, read through the gateway
     * (`GET /v1/audit/settings`). Throws when it cannot be read — guessing
     * would be the dangerous direction. The default applies only when the
     * settings answer and hold no value.
     *
     * @param array{url?: string, endpoint?: string, env?: array<string, string|false|null>, timeoutMs?: int, transport?: callable} $options
     */
    public static function readRetention(mixed $caller, array $options = []): int
    {
        $url = self::auditSettingsUrl($options);
        [$tenant, $token] = self::identity($caller);
        if ($tenant === '') {
            throw new \RuntimeException('trash retention: no tenant');
        }
        $headers = ['Accept: application/json', 'X-Revenexx-Tenant: ' . $tenant];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $transport = $options['transport'] ?? self::curlTransport();
        $answer = $transport($url, $headers, (int) ($options['timeoutMs'] ?? self::DEFAULT_TIMEOUT_MS));
        $status = (int) ($answer['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("trash retention: {$status} from " . self::AUDIT_SETTINGS_PATH, $status);
        }
        $body = json_decode((string) ($answer['body'] ?? ''), true);
        $days = \is_array($body) ? ($body['trash_retention_days'] ?? null) : null;

        return \is_int($days) && $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Tenant, context token and App context of a caller.
     *
     * @return array{string, string, string}
     */
    public static function identity(mixed $caller): array
    {
        if ($caller instanceof RouteContext) {
            return [(string) ($caller->tenant ?? ''), $caller->contextToken, $caller->header('x-revenexx-app-context')];
        }
        $headers = RuntimeAdapter::headers($caller);

        return [$headers['x-revenexx-tenant'] ?? '', $headers['x-revenexx-context'] ?? '', $headers['x-revenexx-app-context'] ?? ''];
    }

    /** @return array<string, mixed> */
    private function load(string $id): array
    {
        if ($id === '') {
            throw HttpError::badRequest("missing path parameter '{$this->idParam}'", null, 'missing_parameter');
        }

        return $this->client->get($this->entity, $id) ?? throw HttpError::notFound();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, took: array<string, int>}
     */
    private function finalDelete(array $row, string $id, mixed $caller): array
    {
        $took = [];
        $batch = $row['trash_batch'] ?? null;
        foreach ($this->children as $child) {
            $rows = $batch === null ? [] : $this->listAll($child['entity'], [$child['column'] => $id, 'trash_batch' => $batch]);
            foreach ($rows as $r) {
                $this->client->remove($child['entity'], (string) $r['id']);
            }
            $took[$child['name']] = \count($rows);
        }
        $deleteRow = $this->options['deleteRow'] ?? null;
        if ($deleteRow !== null) {
            $deleteRow($row, $caller);
        } else {
            $this->client->remove($this->entity, $id);
        }

        return ['id' => $id, 'took' => $took];
    }

    /**
     * Every row matching `$where`, page by page — a group is never cut off.
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private function listAll(Entity $entity, array $where): array
    {
        $rows = [];
        for (;;) {
            $batch = $this->client->list($entity, ['where' => $where, 'limit' => self::PAGE, 'offset' => \count($rows)]);
            array_push($rows, ...array_values($batch));
            if (\count($batch) < self::PAGE) {
                return $rows;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function emit(string $which, array $data, ?string $topicId): void
    {
        $events = $this->options['events'] ?? null;
        $name = \is_array($events) ? ($events[$which] ?? null) : null;
        if (! \is_string($name) || $name === '') {
            return;
        }
        $emit = $events['emit'] ?? null;
        if ($emit !== null) {
            $emit($name, $data, ['topicId' => $topicId]);

            return;
        }
        $this->client->events()->emit($name, $data, ['topicId' => $topicId]);
    }

    /**
     * Run a check; a throw is a blocker, never a pass.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function runCheck(?callable $check, array $row, mixed $caller): array
    {
        if ($check === null) {
            return [];
        }
        try {
            $out = $check($row, $caller);
        } catch (\Throwable $e) {
            return [['reason' => 'could not be checked: ' . $e->getMessage(), 'code' => 'unverifiable']];
        }
        $list = \is_array($out) && \array_key_exists('reason', $out) ? [$out] : (\is_array($out) ? $out : [$out]);

        return array_values(array_filter($list, static fn (mixed $b): bool => \is_array($b) && \is_string($b['reason'] ?? null)));
    }

    /** @param list<array<string, mixed>> $blockers */
    private static function reasons(array $blockers): string
    {
        return implode('; ', array_map(static fn (array $b): string => (string) $b['reason'], $blockers));
    }

    /** @param array<string, mixed> $row */
    private static function isTrashed(array $row): bool
    {
        return ($row[self::TRASH_COLUMN] ?? null) !== null;
    }

    /** @param array<string, mixed> $row */
    private static function idOf(array $row, string $fallback): string
    {
        return (string) ($row['id'] ?? $fallback);
    }

    /** The same instant format as JavaScript's `toISOString()`. */
    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * @param array<string, string|false|null>|null $env
     * @return \Closure(string): string
     */
    public static function envReader(?array $env): \Closure
    {
        return static function (string $name) use ($env): string {
            $value = \is_array($env) ? ($env[$name] ?? null) : getenv($name);

            return \is_string($value) ? $value : '';
        };
    }

    /** @return callable(string, array<int, string>, int): array{status: int, body: string} */
    public static function curlTransport(): callable
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
                throw new \RuntimeException("gateway: {$error}");
            }

            return ['status' => $status, 'body' => (string) $body];
        };
    }
}
