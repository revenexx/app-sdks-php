<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * A record read and sent back: the round trip every edit form makes.
 *
 * A read answers fields no caller sets: the primary key and `tenant_id` (the
 * store's), `created_at` and `updated_at` (the clock's), the trash columns (the
 * trash routes'), and the stored generated columns (the database's). The data
 * plane refused a write naming any of them — the key and the tenant have no
 * UPDATE grant (42501, answered as a 401 that names nothing), the timestamps
 * and generated columns a 400 — so a record sent back unchanged failed.
 *
 * A field that says what the record already says is dropped before the write;
 * one that says something else is refused with a 400 naming it in
 * `details['field']`: `store_owned_field` for the key and the tenant,
 * `read_only_field` for the rest. `updated_at` is always dropped — Baseline
 * stamps it on every write, so a stale copy is the ordinary case. When nothing
 * is left, nothing is written. Same rules as the JavaScript SDK
 * (`packages/sdk/src/patch.ts`).
 */
final class RoundTrip
{
    public const STORE_OWNED_FIELD = 'store_owned_field';

    public const READ_ONLY_FIELD = 'read_only_field';

    /** Stamped by Baseline on every write, so dropped whatever an update says. */
    public const STORE_STAMPED_COLUMNS = ['updated_at'];

    /** Stamped when the record is created; an update may repeat it, never change it. */
    public const CREATION_STAMP_COLUMNS = ['created_at'];

    /** The trash columns a live record reads as null — `purge_at` is declared by the Apps that show it. */
    public const TRASH_ROUND_TRIP_COLUMNS = [...Entity::TRASH_FLAG_COLUMNS, 'purge_at'];

    private const NUMERIC = '/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/';

    private const TENANT_CHANGED = "'tenant_id' cannot be changed: a record stays in the tenant it was written under";

    /**
     * The write an update makes of `$patch`: without the fields that repeat
     * the stored record, refusing the ones that contradict it.
     *
     * - the primary key: equal to `$id` → dropped; another value → 400
     *   `store_owned_field`; null / '' → dropped.
     * - `tenant_id`: equal to the record's → dropped; another → 400
     *   `store_owned_field`; null / '' → dropped. A record that does not carry
     *   its tenant cannot be compared, and the value is dropped.
     * - `updated_at`: always dropped. `created_at`: the same instant, in any
     *   offset → dropped; anything else → 400 `read_only_field`. Both only when
     *   the entity declares them (or carries no column metadata).
     * - a stored generated column, and every column in `$readOnly`: equal to the
     *   stored value → dropped; anything else → 400 `read_only_field`.
     *
     * `$stored` is called at most once, and only when a rule needs the record;
     * when it answers null, {@see RecordGone} is thrown.
     *
     * @param array<string, mixed>                  $columns
     * @param array<string|int, mixed>              $patch
     * @param callable(): (array<string, mixed>|null) $stored
     * @param list<string>                          $readOnly
     * @return array<string|int, mixed>
     */
    public static function update(string $pk, array $columns, int|string $id, array $patch, callable $stored, array $readOnly = []): array
    {
        $out = $patch;
        $loaded = false;
        $current = null;
        $record = static function () use (&$loaded, &$current, $stored): array {
            if (! $loaded) {
                $current = $stored();
                $loaded = true;
            }
            if ($current === null) {
                throw new RecordGone();
            }

            return $current;
        };

        if (\array_key_exists($pk, $out)) {
            if (! self::noValue($out[$pk]) && self::text($out[$pk]) !== (string) $id) {
                throw self::storeOwned($pk, "'{$pk}' cannot be changed: the record is '{$id}'");
            }
            unset($out[$pk]);
        }
        if (\array_key_exists('tenant_id', $out)) {
            if (! self::noValue($out['tenant_id'])) {
                $tenant = $record()['tenant_id'] ?? null;
                if (! self::noValue($tenant) && self::text($out['tenant_id']) !== self::text($tenant)) {
                    throw self::storeOwned('tenant_id', self::TENANT_CHANGED);
                }
            }
            unset($out['tenant_id']);
        }
        foreach (self::STORE_STAMPED_COLUMNS as $field) {
            if (\array_key_exists($field, $out) && self::declares($columns, $field)) {
                unset($out[$field]);
            }
        }
        foreach (self::CREATION_STAMP_COLUMNS as $field) {
            if (! \array_key_exists($field, $out) || ! self::declares($columns, $field)) {
                continue;
            }
            if (! self::sameInstant($out[$field], $record()[$field] ?? null)) {
                throw self::readOnly($field, 'stamped by the platform');
            }
            unset($out[$field]);
        }
        $generated = self::generatedColumns($columns);
        $fixed = array_values(array_unique([...$generated, ...$readOnly]));
        sort($fixed, SORT_STRING);
        foreach ($fixed as $field) {
            if (! \array_key_exists($field, $out)) {
                continue;
            }
            if (! self::sameValue($out[$field], $record()[$field] ?? null)) {
                throw self::readOnly($field, \in_array($field, $generated, true) ? 'computed by the database' : 'kept by the app');
            }
            unset($out[$field]);
        }

        return $out;
    }

    /**
     * The write a create makes of a body: the key the store assigns is refused
     * with a value and dropped without one; `tenant_id` naming another tenant
     * than the request's is refused, and dropped otherwise (also when there is
     * no request tenant to compare with — the data plane writes the tenant).
     *
     * @param array<string, mixed>     $columns
     * @param array<string|int, mixed> $body
     * @return array<string|int, mixed>
     */
    public static function create(string $pk, array $columns, array $body, ?string $tenant): array
    {
        $out = $body;
        if (\array_key_exists($pk, $out) && self::storeAssignsKey($pk, $columns)) {
            if (! self::noValue($out[$pk])) {
                throw self::storeOwned($pk, "'{$pk}' is assigned by the store and cannot be sent on a create");
            }
            unset($out[$pk]);
        }
        if (\array_key_exists('tenant_id', $out)) {
            if (! self::noValue($out['tenant_id']) && ! self::noValue($tenant) && self::text($out['tenant_id']) !== (string) $tenant) {
                throw self::storeOwned('tenant_id', "'tenant_id' is the tenant of the request and cannot name another");
            }
            unset($out['tenant_id']);
        }

        return $out;
    }

    /**
     * A body without the trash columns a live record reads as null; a trash
     * column with a value stays, for {@see Entity::assertNoTrashFields()}.
     *
     * @param array<string|int, mixed> $body
     * @return array<string|int, mixed>
     */
    public static function withoutNullTrash(array $body): array
    {
        foreach (self::TRASH_ROUND_TRIP_COLUMNS as $k) {
            if (\array_key_exists($k, $body) && $body[$k] === null) {
                unset($body[$k]);
            }
        }

        return $body;
    }

    /**
     * Whether the store assigns the key on create: a serial / bigserial key, or
     * one whose default is gen_random_uuid(). A key without a default is the caller's.
     *
     * @param array<string, mixed> $columns
     */
    public static function storeAssignsKey(string $pk, array $columns): bool
    {
        $def = $columns[$pk] ?? null;
        if (! \is_array($def)) {
            return false;
        }
        if (preg_match('/^(big)?serial\b/i', (string) ($def['type'] ?? ''))) {
            return true;
        }

        return (bool) preg_match('/gen_random_uuid/i', (string) ($def['default'] ?? ''));
    }

    /**
     * The stored generated columns (`generated` in schema.json), sorted.
     *
     * @param array<string, mixed> $columns
     * @return list<string>
     */
    public static function generatedColumns(array $columns): array
    {
        $out = [];
        foreach ($columns as $name => $def) {
            if (\is_array($def) && ! empty($def['generated'])) {
                $out[] = (string) $name;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /** Two timestamps are the same instant, whatever offset or precision either is written in. */
    public static function sameInstant(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (self::text($a) === self::text($b)) {
            return true;
        }
        if (! \is_string($a) || ! \is_string($b) || ! preg_match(self::INSTANT, $a) || ! preg_match(self::INSTANT, $b)) {
            return false;
        }
        try {
            $x = new \DateTimeImmutable($a);
            $y = new \DateTimeImmutable($b);
        } catch (\Exception) {
            return false;
        }

        // Milliseconds, as the JavaScript runtime compares them.
        $ms = static fn (\DateTimeImmutable $t): int => (int) $t->format('U') * 1000 + intdiv((int) $t->format('u'), 1000);

        return $ms($x) === $ms($y);
    }

    /** Two values are the same as a read answers them: documents by JSON, numbers by value, instants by instant. */
    public static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (\is_array($a) || \is_array($b)) {
            return self::canonical($a) === self::canonical($b);
        }
        if (self::text($a) === self::text($b)) {
            return true;
        }
        $numeric = static fn (mixed $v): bool => \is_int($v) || \is_float($v) || (\is_string($v) && preg_match(self::NUMERIC, trim($v)));
        if ($numeric($a) && $numeric($b)) {
            return (float) $a === (float) $b;
        }

        return self::sameInstant($a, $b);
    }

    private static function canonical(mixed $v): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (! \is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return $v;
        };

        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** A value as JavaScript's String() writes it. */
    private static function text(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return \is_scalar($v) ? (string) $v : (string) json_encode($v);
    }

    private static function noValue(mixed $v): bool
    {
        return $v === null || $v === '';
    }

    /** @param array<string, mixed> $columns */
    private static function declares(array $columns, string $column): bool
    {
        return $columns === [] || \array_key_exists($column, $columns);
    }

    private static function storeOwned(string $field, string $message): HttpError
    {
        return HttpError::badRequest($message, ['field' => $field, 'fields' => [$field]], self::STORE_OWNED_FIELD);
    }

    private static function readOnly(string $field, string $what): HttpError
    {
        return HttpError::badRequest(
            "'{$field}' is {$what} and cannot be changed by a write — send it as read, or leave it out",
            ['field' => $field, 'fields' => [$field]],
            self::READ_ONLY_FIELD,
        );
    }
}
