<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Low-level entity client: routes ops to the adapter, enforcing the per-entity
 * permission gate. The generated `Db` (from `revenexx apps generate`) wraps this
 * with typed repositories and DTOs.
 */
final class Client
{
    /** The one wording both runtimes use for "you sent nothing to update". */
    public const EMPTY_UPDATE_MESSAGE = 'request body must contain at least one field to update';

    /** The one wording both runtimes use for "a conditional update with no condition". */
    public const EMPTY_CONDITION_MESSAGE = 'a conditional update needs at least one condition in where';

    /**
     * @param array<string, Entity>          $entities
     * @param array<string, EventDescriptor> $declaredEvents the App's own `trigger: "app"` events
     * @param ?string                        $emitFn         the routine the platform generated for them
     */
    public function __construct(
        private readonly Adapter $adapter,
        private readonly array $entities,
        private readonly ?string $emitFn = null,
        private readonly array $declaredEvents = [],
    ) {
    }

    public function adapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * The App's own events. Throws when the manifest declares none — there is
     * no routine to call, and a silent no-op would be worse than the error.
     */
    public function events(): Events
    {
        if ($this->emitFn === null || $this->emitFn === '') {
            throw new \RuntimeException(
                'app-sdk: this app declares no app-fired events — add one to manifest.json events.emits[] with "trigger": "app"',
            );
        }

        return new Events($this->adapter, $this->emitFn, $this->declaredEvents);
    }

    public function entity(string $name): Entity
    {
        return $this->entities[$name]
            ?? throw new \InvalidArgumentException("app-sdk: unknown entity '{$name}'");
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function list(Entity $entity, array $query = []): array
    {
        $this->guard($entity, 'list');

        return $this->adapter->list($entity, $query);
    }

    /**
     * The rows of one page and the number of rows matching overall. Needs an
     * adapter that can count ({@see CountsRows}); one that cannot says so
     * rather than answering a total it does not know.
     *
     * @param array<string, mixed> $query
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: ?int, offset: ?int}
     */
    public function page(Entity $entity, array $query = []): array
    {
        $this->guard($entity, 'list', 'page');
        if (! $this->adapter instanceof CountsRows) {
            throw new \RuntimeException("{$entity->name}.page() is not supported by the '{$this->adapter->kind()}' adapter");
        }

        return $this->adapter->page($entity, $query);
    }

    /** @return array<string, mixed>|null */
    public function get(Entity $entity, int|string $id): ?array
    {
        $this->guard($entity, 'get');

        return $this->adapter->get($entity, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Entity $entity, array $data): array
    {
        $this->guard($entity, 'create');
        // A key that is not a column this version declares is refused before
        // the request ({@see Entity::unknownFields()}).
        $entity->assertKnownFields($data);
        Entity::assertCreatable($data);

        // A refusal of the tenant or a store-assigned key names the field
        // ({@see RoundTrip::storeOwnedRefusal()}), not "unauthenticated".
        return $this->namingStoreOwned($entity, $data, fn (): array => $this->adapter->create($entity, $data));
    }

    /**
     * Bulk create: a non-empty list of records naming the same fields, inserted
     * in one request ({@see Entity::bulkRecords()} checks every record first; a
     * refusal names it in `details['index']`). Answers the created rows, in
     * order. Gated by the `create` permission. `create()` takes one record, so
     * a handler that writes its request body never becomes a bulk endpoint.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function createMany(Entity $entity, array $rows): array
    {
        $this->guard($entity, 'create', 'createMany');
        $records = $entity->bulkRecords($rows);
        if (! $this->adapter instanceof CreatesMany) {
            throw new \RuntimeException("{$entity->name}.createMany() is not supported by the '{$this->adapter->kind()}' adapter");
        }

        $adapter = $this->adapter;

        return $this->namingStoreOwned($entity, $records, static fn (): array => $adapter->createMany($entity, $records));
    }

    /**
     * A patch with no fields is rejected before it reaches the adapter — it is
     * a malformed request, not a write. PostgREST answers a column-less PATCH
     * with `200 []`, so the adapter would have no row to return; the JavaScript
     * SDK has the same rule.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $this->guard($entity, 'update');
        if ($patch === []) {
            throw HttpError::badRequest(self::EMPTY_UPDATE_MESSAGE, null, 'empty_update');
        }
        $entity->assertKnownFields($patch);
        // A record read and sent back names fields no write may set
        // ({@see RoundTrip::update()}): the ones that repeat the record are
        // dropped, the ones that contradict it refused. Nothing left is no
        // write at all — the record as it stands, no update event.
        $stored = $this->storedOnce($entity, $id);
        try {
            $write = RoundTrip::update($entity->pk, $entity->columns, $id, $patch, $stored);
        } catch (RecordGone) {
            throw HttpError::notFound();
        }
        if ($write === []) {
            return $stored() ?? throw HttpError::notFound();
        }

        return $this->namingStoreOwned($entity, $write, fn (): array => $this->adapter->update($entity, $id, $write));
    }

    /**
     * Run a write, answering a grant refusal of `tenant_id` or the key by name.
     *
     * @template T
     * @param array<array-key, mixed> $body
     * @param callable(): T $write
     * @return T
     */
    private function namingStoreOwned(Entity $entity, array $body, callable $write): mixed
    {
        try {
            return $write();
        } catch (HttpError $err) {
            throw RoundTrip::storeOwnedRefusal($entity->pk, $entity->columns, $body, $err) ?? $err;
        }
    }

    /**
     * One read of a record, shared by every rule that needs it.
     *
     * @return callable(): (array<string, mixed>|null)
     */
    private function storedOnce(Entity $entity, int|string $id): callable
    {
        $read = false;
        $row = null;

        return function () use (&$read, &$row, $entity, $id): ?array {
            if (! $read) {
                $row = $this->adapter->get($entity, $id);
                $read = true;
            }

            return $row;
        };
    }

    /**
     * Compare-and-set: update the row only if it still matches `$where`, in one
     * write. Null when no row matched — the row is gone, invisible, or the
     * condition no longer holds; a lost race is an outcome to branch on, not an
     * error. Gated by the `update` permission.
     *
     * An empty `$where` is refused: it is an unconditional update spelled as a
     * conditional one, and would let through the race the caller meant to
     * close. `update()` is the unconditional write. Same rule as the
     * JavaScript SDK.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|null
     */
    public function updateIf(Entity $entity, int|string $id, array $where, array $patch): ?array
    {
        $this->guard($entity, 'update', 'updateIf');
        if ($where === []) {
            throw HttpError::badRequest(self::EMPTY_CONDITION_MESSAGE, null, 'empty_condition');
        }
        if ($patch === []) {
            throw HttpError::badRequest(self::EMPTY_UPDATE_MESSAGE, null, 'empty_update');
        }
        $entity->assertKnownFields($patch);
        if (! $this->adapter instanceof UpdatesConditionally) {
            throw new \RuntimeException(
                "{$entity->name}.updateIf() is not supported by the '{$this->adapter->kind()}' adapter",
            );
        }

        try {
            $write = RoundTrip::update($entity->pk, $entity->columns, $id, $patch, $this->storedOnce($entity, $id));
        } catch (RecordGone) {
            return null;
        }
        // Nothing left to write: the row while the condition holds, else null —
        // one read, the condition ANDed onto the key as the write would have.
        if ($write === []) {
            $rows = $this->adapter->list($entity, ['where' => [...$where, $entity->pk => $id], 'limit' => 1]);

            return $rows[0] ?? null;
        }

        $adapter = $this->adapter;

        return $this->namingStoreOwned($entity, $write, static fn (): ?array => $adapter->updateIf($entity, $id, $where, $write));
    }

    public function remove(Entity $entity, int|string $id): void
    {
        $this->guard($entity, 'delete');
        $this->adapter->remove($entity, $id);
    }

    /** `$method` names what the App called when it differs from the op it needs. */
    private function guard(Entity $entity, string $op, ?string $method = null): void
    {
        // The document columns a request reaching this entity answers as stored.
        AnswerScope::note($entity->columns);
        if (! $entity->allows($op)) {
            $method ??= $op;
            throw HttpError::forbidden(
                "{$entity->name}.{$method}() is not permitted — add the matching access to manifest.permissions for '{$entity->name}'",
                'not_permitted',
            );
        }
    }
}
