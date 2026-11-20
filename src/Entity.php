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

    /**
     * Columns every table has without declaring them in schema.json — Baseline
     * adds them, so the generated column list does not name them. Writing one
     * is left as it was before the field check existed.
     */
    public const IMPLICIT_COLUMNS = ['tenant_id'];

    public function allows(string $op): bool
    {
        return \in_array($op, $this->ops, true);
    }

    /**
     * The keys of a write that are not columns this version declares, sorted.
     *
     * A version writes only the columns it declares. During a rollout the new
     * schema is applied before the new code is served, so the database alone
     * would let the old code store a key it never knew into the new column.
     * Only the top level is checked: a jsonb column takes any document. An
     * entity without column metadata is not checked. Same rule as the
     * JavaScript SDK.
     *
     * @param array<string|int, mixed> $data
     * @return list<string>
     */
    public function unknownFields(array $data): array
    {
        if ($this->columns === []) {
            return [];
        }
        $unknown = [];
        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            if (! \array_key_exists($key, $this->columns) && ! \in_array($key, self::IMPLICIT_COLUMNS, true)) {
                $unknown[] = $key;
            }
        }
        sort($unknown, SORT_STRING);

        return $unknown;
    }

    /**
     * Throw a 400 `unknown_field` when a write carries a key that is not a
     * column. Refused, never dropped: dropping would turn a caller's mistake
     * into lost data with no signal.
     *
     * @param array<string|int, mixed> $data
     */
    public function assertKnownFields(array $data): void
    {
        $unknown = $this->unknownFields($data);
        if ($unknown === []) {
            return;
        }
        $quoted = implode(', ', array_map(static fn (string $k): string => "'{$k}'", $unknown));
        $allowed = array_map('strval', array_keys($this->columns));
        sort($allowed, SORT_STRING);
        $plural = \count($unknown) > 1 ? 's' : '';

        throw HttpError::badRequest(
            "unknown field{$plural} {$quoted} — not a column of '{$this->name}'. "
                . 'A field that cannot be written is refused rather than dropped; see details.allowed.',
            ['unknown' => $unknown, 'allowed' => $allowed],
            'unknown_field',
        );
    }
}
