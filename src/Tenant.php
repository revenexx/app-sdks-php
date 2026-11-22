<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Rows the data client returns carry no `tenant_id` — the PHP counterpart of
 * the JavaScript SDK's tenant strip.
 *
 * The caller of an App already named its tenant to reach the data, so the key
 * told it nothing, and it named the store's partitioning on every answer.
 * {@see Client} leaves it out of every row it returns — reads, and the rows a
 * write answers — and out of every embedded relation row its `select` names, at
 * any depth of embedding. It happens where the row level is known, so a `json`
 * / `jsonb` column's value is never touched: a stored payload keeps its own
 * `tenant_id`, and a record read and sent back writes it back as it was.
 *
 * The rules that compare against the stored tenant read the row from the
 * adapter. An App that must read the key creates the client with
 * `keepTenantId: true`.
 */
final class Tenant
{
    /** The key a row the data client returns does not carry. */
    public const KEY = 'tenant_id';

    /**
     * A record, or each record of a list, without its top-level `tenant_id`.
     * Shallow on purpose: for rows an App gets past the data client, which it
     * knows to be records. Nested values are left as they are.
     */
    public static function withoutTenantId(mixed $rows): mixed
    {
        if (\is_array($rows) && array_is_list($rows) && $rows !== []) {
            return array_map(static fn (mixed $row): mixed => self::readRow($row, []), $rows);
        }

        return self::readRow($rows, []);
    }

    /**
     * The embedded relations a PostgREST `select` names — `lines(*)`,
     * `buyer:customers(*)`, `lines(*,product(*))`, `owner:users!owner_id(*)` —
     * keyed by the key a row carries them under (the alias, else the name).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function embedsOf(mixed $select): array
    {
        $entries = \is_array($select) ? array_map('strval', $select) : (\is_string($select) ? [$select] : []);
        $out = [];
        foreach ($entries as $entry) {
            foreach (self::split($entry) as $part) {
                $part = trim($part);
                $open = strpos($part, '(');
                if ($open === false || $open === 0 || ! str_ends_with($part, ')')) {
                    continue;
                }
                $head = trim(substr($part, 0, $open));
                $colon = strpos($head, ':');
                $key = trim($colon !== false ? substr($head, 0, $colon) : explode('!', $head)[0]);
                if ($key !== '') {
                    $out[$key] = self::embedsOf(substr($part, $open + 1, -1));
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function split(string $select): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $len = \strlen($select);
        for ($i = 0; $i < $len; $i++) {
            $ch = $select[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = substr($select, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($select, $start);

        return array_values(array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * @param list<mixed>                         $rows
     * @param array<string, array<string, mixed>> $embeds
     * @return list<mixed>
     */
    public static function readRows(array $rows, array $embeds = []): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i] = self::readRow($row, $embeds);
        }

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $embeds */
    public static function readRow(mixed $row, array $embeds = []): mixed
    {
        if (! \is_array($row) || ($row !== [] && array_is_list($row))) {
            return $row;
        }
        unset($row[self::KEY]);
        foreach ($embeds as $key => $nested) {
            $item = $row[$key] ?? null;
            if (! \is_array($item)) {
                continue;
            }
            $row[$key] = array_is_list($item) ? self::readRows($item, $nested) : self::readRow($item, $nested);
        }

        return $row;
    }
}
