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

    /**
     * @param array<string, Entity>          $entities
     * @param array<string, EventDescriptor> $declaredEvents the App's own `trigger: "app"` events
     * @param ?string                        $emitFn         the routine Baseline generated for them
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

        return $this->adapter->create($entity, $data);
    }

    /**
     * A patch with no fields is rejected before it reaches the adapter — it is
     * a malformed request, not a write. PostgREST answers a column-less PATCH
     * with `200 []`, so the adapter would have no row to return; the TS runtime
     * has the same rule (packages/sdk/src/patch.ts).
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $this->guard($entity, 'update');
        if ($patch === []) {
            throw new \InvalidArgumentException(self::EMPTY_UPDATE_MESSAGE);
        }

        return $this->adapter->update($entity, $id, $patch);
    }

    public function remove(Entity $entity, int|string $id): void
    {
        $this->guard($entity, 'delete');
        $this->adapter->remove($entity, $id);
    }

    private function guard(Entity $entity, string $op): void
    {
        if (! $entity->allows($op)) {
            throw new \RuntimeException(
                "{$entity->name}.{$op}() is not permitted — add the matching access to manifest.permissions for '{$entity->name}'",
            );
        }
    }
}
