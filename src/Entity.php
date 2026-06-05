<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * A generated entity descriptor: maps an App entity to its fully-namespaced
 * Postgres table, primary key, columns and the ops permitted by the manifest.
 */
final class Entity
{
    /**
     * @param array<string, mixed> $columns schema.json column definitions
     * @param string[]             $ops      permitted ops (list|get|create|update|delete)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $pk = 'id',
        public readonly array $columns = [],
        public readonly array $ops = [],
    ) {
    }

    public function allows(string $op): bool
    {
        return \in_array($op, $this->ops, true);
    }
}
