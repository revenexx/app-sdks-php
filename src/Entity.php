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
     * @param bool                 $trash    schema.json `trash: true` — Baseline adds {@see self::TRASH_FLAG_COLUMNS}
     * @param bool                 $externalId schema.json `external_id: true` — Baseline adds {@see self::EXTERNAL_ID_FLAG_COLUMNS}
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $pk = 'id',
        public readonly array $columns = [],
        public readonly array $ops = [],
        public readonly bool $trash = false,
        public readonly bool $externalId = false,
    ) {
    }

    /**
     * Columns every table has without declaring them in schema.json — Baseline
     * adds them, so the generated column list does not name them. Writing one
     * is left as it was before the field check existed.
     */
    public const IMPLICIT_COLUMNS = ['tenant_id'];

    /**
     * The columns `trash: true` adds to an entity that does not declare them
     * itself: when the row went into the trash, who put it there (by name and
     * by subject id), the group it went with, and why it may not be purged yet.
     */
    public const TRASH_FLAG_COLUMNS = ['deleted_at', 'deleted_by', 'deleted_by_id', 'trash_batch', 'purge_blocked_reason'];

    /** The column `external_id: true` adds to an entity that does not declare it. */
    public const EXTERNAL_ID_FLAG_COLUMNS = ['external_id'];

    /**
     * The columns Baseline adds for this entity's schema flags, whether or not
     * the App declares them as well.
     *
     * @return list<string>
     */
    public function flagColumns(): array
    {
        return [
            ...($this->trash ? self::TRASH_FLAG_COLUMNS : []),
            ...($this->externalId ? self::EXTERNAL_ID_FLAG_COLUMNS : []),
        ];
    }

    /**
     * Every column a write may name, sorted: the declared columns plus the ones
     * the flags add. `tenant_id` is accepted on top without being listed.
     *
     * @return list<string>
     */
    public function writableColumns(): array
    {
        $columns = array_values(array_unique([...array_map('strval', array_keys($this->columns)), ...$this->flagColumns()]));
        sort($columns, SORT_STRING);

        return $columns;
    }

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
     * The columns the `trash` and `external_id` flags add count as declared:
     * Baseline creates them from the flag, and an App that sets only the flag
     * still writes them (the trash routes, an import keeping the foreign key).
     *
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
        $writable = [...$this->writableColumns(), ...self::IMPLICIT_COLUMNS];
        $unknown = [];
        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            if (! \in_array($key, $writable, true)) {
                $unknown[] = $key;
            }
        }
        sort($unknown, SORT_STRING);

        return $unknown;
    }

    /** The one wording every layer uses for "you sent nothing to create". */
    public const EMPTY_CREATE_MESSAGE = 'request body must contain at least one field to create';

    /**
     * Throw a 400 `empty_create` when a create carries no field. `[]` (which is
     * also what `{}` decodes to) used to go to PostgREST as a bulk insert of no
     * rows and come back as `500 empty_representation`, while the mock stored a
     * row of defaults. Same rule as the JavaScript SDK.
     *
     * @param array<string|int, mixed> $data
     */
    public static function assertCreatable(array $data): void
    {
        if ($data === []) {
            throw HttpError::badRequest(self::EMPTY_CREATE_MESSAGE, null, 'empty_create');
        }
    }

    /** The one wording every layer uses for "a write takes one record". */
    public const ONE_RECORD_MESSAGE = 'a write takes one record, as a JSON object — not a list';

    /**
     * Throw a 400 `bad_request` when a write is a list rather than one record.
     *
     * PostgREST reads a JSON array posted to a table as a bulk insert, every
     * element's keys columns, so a list would carry keys the field check never
     * saw. Checked wherever the field check runs (an entity with column
     * metadata), and by {@see Router\Crud::mount()} for every body it routes.
     * An empty array is an empty record, not a list. Same rule as the
     * JavaScript SDK.
     *
     * @param array<string|int, mixed> $data
     */
    public function assertOneRecord(array $data): void
    {
        if ($this->columns !== [] && self::isList($data)) {
            throw self::oneRecordRefusal();
        }
    }

    /**
     * Whether a body is a list: a non-empty array whose keys are exactly the
     * indexes 0 … n-1, in any order. A JSON array decodes to one, and so does
     * an object keyed by indexes (`{"0":…}`); `[]` is the same array as `{}`,
     * so an empty list is an empty record. The JavaScript SDK applies the same
     * definition (`isList`).
     *
     * @param array<string|int, mixed> $data
     */
    public static function isList(array $data): bool
    {
        $n = \count($data);
        if ($n === 0) {
            return false;
        }
        foreach (array_keys($data) as $k) {
            if (! \is_int($k) || $k < 0 || $k >= $n) {
                return false;
            }
        }

        return true;
    }

    /**
     * The trash columns a body sets, sorted — on an entity with `trash: true`
     * only. The trash routes keep them ({@see Router\Trash::mount()}): a body
     * sent to a mounted collection that set them would trash a record with a
     * forged `deleted_by`, or restore and unblock it, past the guard, the
     * children and the purge rules. So `Crud::mount` refuses them; the client
     * still writes them for the trash routes. Same as the JavaScript SDK.
     *
     * @param array<string|int, mixed> $body
     * @return list<string>
     */
    public function trashFieldsIn(array $body): array
    {
        if (! $this->trash) {
            return [];
        }
        $fields = array_values(array_filter(self::TRASH_FLAG_COLUMNS, static fn (string $k): bool => \array_key_exists($k, $body)));
        sort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Throw a 400 `read_only_field` when a mounted write sets a trash column.
     *
     * @param array<string|int, mixed> $body
     */
    public function assertNoTrashFields(array $body): void
    {
        $fields = $this->trashFieldsIn($body);
        if ($fields === []) {
            return;
        }
        $quoted = implode(', ', array_map(static fn (string $k): string => "'{$k}'", $fields));
        $many = \count($fields) > 1;

        throw HttpError::badRequest(
            'field' . ($many ? 's' : '') . " {$quoted} " . ($many ? 'are' : 'is') . ' kept by the trash — '
                . 'put a record into the trash, restore or purge it through the trash routes.',
            ['fields' => $fields],
            'read_only_field',
        );
    }

    /** The refusal for a list where one record belongs. */
    public static function oneRecordRefusal(): HttpError
    {
        return HttpError::badRequest(self::ONE_RECORD_MESSAGE, null, 'bad_request');
    }

    /**
     * Throw a 400 `unknown_field` when a write carries a key that is not a
     * column. Refused, never dropped: dropping would turn a caller's mistake
     * into lost data with no signal. A list is refused first
     * ({@see self::assertOneRecord()}).
     *
     * @param array<string|int, mixed> $data
     */
    public function assertKnownFields(array $data): void
    {
        $this->assertOneRecord($data);
        $unknown = $this->unknownFields($data);
        if ($unknown === []) {
            return;
        }
        $quoted = implode(', ', array_map(static fn (string $k): string => "'{$k}'", $unknown));
        $allowed = $this->writableColumns();
        $plural = \count($unknown) > 1 ? 's' : '';

        throw HttpError::badRequest(
            "unknown field{$plural} {$quoted} — not a column of '{$this->name}'. "
                . 'A field that cannot be written is refused rather than dropped; see details.allowed.',
            ['unknown' => $unknown, 'allowed' => $allowed],
            'unknown_field',
        );
    }
}
