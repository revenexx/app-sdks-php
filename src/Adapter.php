<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * The data-plane contract. Implement this to add a new backend and register it
 * with {@see AdapterRegistry::register()}.
 *
 * Rows are associative arrays (column => value), mirroring PostgREST JSON.
 */
interface Adapter
{
    public function kind(): string;

    /**
     * @param array<string, mixed> $query { where, select, order, limit, offset }
     * @return array<int, array<string, mixed>>
     */
    public function list(Entity $entity, array $query = []): array;

    /** @return array<string, mixed>|null */
    public function get(Entity $entity, int|string $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Entity $entity, array $data): array;

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(Entity $entity, int|string $id, array $patch): array;

    public function remove(Entity $entity, int|string $id): void;
}
