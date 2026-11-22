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

    /** Stamped by Baseline on every write: dropped from an update, unless it is all the update sends (a touch). */
    public const STORE_STAMPED_COLUMNS = ['updated_at'];

    /** Stamped when the record is created: dropped when an update repeats it, written when it corrects it. */
    public const CREATION_STAMP_COLUMNS = ['created_at'];

    /** The trash columns a live record reads as null — `purge_at` is declared by the Apps that show it. */
    public const TRASH_ROUND_TRIP_COLUMNS = [...Entity::TRASH_FLAG_COLUMNS, 'purge_at'];

    private const DECIMAL = '/^([+-]?)(\d*)(?:\.(\d*))?$/';

    private const NUMERIC_TYPE = '/^(?:(?:small|big)?int(?:eger)?|int[248]|(?:big|small)?serial|serial[248]|numeric|decimal|real|double|float[48]?)\b/i';

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
     * - `updated_at`: dropped, unless it is the only field left once the key and
     *   the tenant are — a touch, whose
     *   write makes Baseline's trigger stamp the time. `created_at`: the same
     *   instant, in any offset → dropped; another instant is written (a
     *   correction). Both only when the entity declares them (or carries no
     *   column metadata).
     * - a stored generated column: always dropped — Postgres computes it.
     * - every column in `$readOnly`: equal to the stored value (by the column's
     *   type) → dropped; anything else → 400 `read_only_field`.
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
        // A patch of nothing but updated_at — once the key and the tenant that
        // repeat the record are gone — is a touch: the write is the point.
        $touch = \count($out) === 1 && \in_array((string) array_key_first($out), self::STORE_STAMPED_COLUMNS, true);
        foreach (self::STORE_STAMPED_COLUMNS as $field) {
            if (! $touch && \array_key_exists($field, $out) && self::declares($columns, $field)) {
                unset($out[$field]);
            }
        }
        foreach (self::CREATION_STAMP_COLUMNS as $field) {
            if (! \array_key_exists($field, $out) || ! self::declares($columns, $field)) {
                continue;
            }
            // Another instant is written, as before: a correction.
            if (self::sameInstant($out[$field], $record()[$field] ?? null)) {
                unset($out[$field]);
            }
        }
        // Postgres computes a generated column: dropped whatever it says.
        $generated = self::generatedColumns($columns);
        foreach ($generated as $field) {
            unset($out[$field]);
        }
        $fixed = array_values(array_unique($readOnly));
        sort($fixed, SORT_STRING);
        foreach ($fixed as $field) {
            if (! \array_key_exists($field, $out) || \in_array($field, $generated, true)) {
                continue;
            }
            $type = \is_array($columns[$field] ?? null) ? ($columns[$field]['type'] ?? null) : null;
            if (! self::sameValue($out[$field], $record()[$field] ?? null, $type)) {
                throw self::readOnly($field, 'kept by the app');
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
     * An update's body without the trash columns sent as null that the stored
     * record holds as null too — what a live record reads. A null the record
     * does not hold (a record in the trash) stays, for
     * {@see Entity::assertNoTrashFields()} to refuse: it would restore the
     * record past the trash routes. `purge_at` stays to be written as before.
     *
     * @param array<string|int, mixed> $body
     * @param array<string, mixed>     $stored
     * @return array<string|int, mixed>
     */
    public static function trashRoundTripped(array $body, array $stored): array
    {
        foreach (self::TRASH_ROUND_TRIP_COLUMNS as $k) {
            if (\array_key_exists($k, $body) && $body[$k] === null && ($stored[$k] ?? null) === null) {
                unset($body[$k]);
            }
        }

        return $body;
    }

    /**
     * A body without its trash columns sent as null — for a create, where the
     * new record holds exactly that; a trash column with a value stays, for
     * {@see Entity::assertNoTrashFields()}.
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
     * A write the data plane refused for want of a grant, naming the field it
     * was refused for.
     *
     * Baseline grants no INSERT on `tenant_id`, nor on a key the store assigns,
     * and no UPDATE on either. Postgres refuses a write naming such a column
     * with SQLSTATE 42501, which PostgREST answers 401/403 and the adapters map
     * to `bad_request` "unauthenticated". When the refused write named
     * `tenant_id` or the key, the refusal is `400 store_owned_field` naming it,
     * as `Crud::mount` answers before the request. `tenant_id` is named first:
     * it is never granted, while a key may be. Any other 42501 keeps its
     * mapping; null when `$err` is not such a refusal. Same rule as the
     * JavaScript SDK.
     *
     * @param array<array-key, mixed> $body one record, or a list of them
     */
    public static function storeOwnedRefusal(string $pk, array $body, HttpError $err): ?HttpError
    {
        $detail = $err->serverDetail;
        if (! \is_array($detail) || ($detail['sqlstate'] ?? null) !== '42501') {
            return null;
        }
        $records = array_is_list($body) && $body !== [] && \is_array($body[0]) ? $body : [$body];
        $pk = $pk !== '' ? $pk : 'id';
        foreach (array_unique(['tenant_id', $pk]) as $field) {
            foreach ($records as $record) {
                if (\is_array($record) && \array_key_exists($field, $record)) {
                    $message = $field === 'tenant_id'
                        ? "'tenant_id' is the tenant of the request and cannot be written"
                        : "'{$field}' is assigned by the store and cannot be written";

                    return new HttpError(400, $message, ['field' => $field, 'fields' => [$field]], self::STORE_OWNED_FIELD, $detail);
                }
            }
        }

        return null;
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

    /** Whether a column type compares by number (`int`, `bigint`, `numeric(14,3)`, `real`, …). */
    public static function isNumericType(mixed $type): bool
    {
        return \is_string($type) && (bool) preg_match(self::NUMERIC_TYPE, trim($type));
    }

    /**
     * Two values are the same as a read answers them: documents by JSON,
     * instants by instant, a column of a numeric type by number — exactly, so
     * a big integer is never rounded — and the rest as text.
     */
    public static function sameValue(mixed $a, mixed $b, mixed $type = null): bool
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
        if (self::isNumericType($type)) {
            $x = self::decimal($a);
            $y = self::decimal($b);
            if ($x !== null && $y !== null) {
                return $x === $y;
            }
            // An exponent (1e21) is no plain decimal; compare it as a float.
            return is_numeric($a) && is_numeric($b) && (float) $a === (float) $b;
        }

        return self::sameInstant($a, $b);
    }

    /** A plain decimal normalised exactly (sign, no leading or trailing zeros), or null. */
    private static function decimal(mixed $v): ?string
    {
        $text = \is_int($v) ? (string) $v : (\is_float($v) ? (is_finite($v) ? self::text($v) : '') : (\is_string($v) ? trim($v) : ''));
        if (! preg_match(self::DECIMAL, $text, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }
        $whole = ltrim($m[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($m[3] ?? '', '0');
        $digits = $fraction !== '' ? "{$whole}.{$fraction}" : $whole;

        return $digits === '0' ? '0' : ($m[1] === '-' ? '-' : '') . $digits;
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

        // No JSON_PRESERVE_ZERO_FRACTION: JavaScript reads 1.0 as 1, so 1.0 and 1 are one value.
        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A value as JavaScript's String() writes it. */
    private static function text(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if (\is_float($v)) {
            // Shortest round trip, as JavaScript writes a number (0.1 + 0.2, 1e+21).
            return str_replace('.0e', 'e', (string) json_encode($v));
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
