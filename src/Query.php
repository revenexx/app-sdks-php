<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * PostgREST query helpers. `toParams` serialises a query for the remote adapter;
 * `matches` applies the same `where` semantics in memory for the mock adapter.
 *
 * Query shape (associative array):
 *   where:  ['col' => value]                       // col = eq.value
 *         | ['col' => [a, b]]                       // col = in.(a,b)
 *         | ['col' => ['op' => 'gte', 'value' => 5]]
 *   select: ['id', 'name']
 *   order:  'col' | 'col.asc' | 'col.desc'
 *   limit / offset: int
 */
final class Query
{
    private const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'in', 'is'];

    /** @param array<string, mixed> $query */
    public static function toParams(array $query): string
    {
        // Every key and value is percent-encoded, so a value carrying `&`, `=`,
        // a space or a non-ASCII character stays one value — the same answer
        // the TS runtime's URLSearchParams gives. PostgREST decodes it.
        $pairs = [];
        foreach (($query['where'] ?? []) as $col => $cond) {
            $pairs[] = rawurlencode((string) $col) . '=' . rawurlencode(self::encodeCondition($cond));
        }

        if (! empty($query['select']) && \is_array($query['select'])) {
            $pairs[] = 'select=' . rawurlencode(implode(',', $query['select']));
        }
        if (! empty($query['order'])) {
            $order = (string) $query['order'];
            $pairs[] = 'order=' . rawurlencode(preg_match('/\.(asc|desc)$/', $order) ? $order : $order . '.asc');
        }
        if (isset($query['limit'])) {
            $pairs[] = 'limit=' . (int) $query['limit'];
        }
        if (isset($query['offset'])) {
            $pairs[] = 'offset=' . (int) $query['offset'];
        }

        return implode('&', $pairs);
    }

    private static function encodeCondition(mixed $cond): string
    {
        if (\is_array($cond) && isset($cond['op'])) {
            $op = (string) $cond['op'];
            if (! \in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException("query: unsupported operator '{$op}'");
            }
            $value = $cond['value'] ?? null;
            $v = \is_array($value) ? '(' . implode(',', $value) . ')' : self::scalar($value);

            return "{$op}.{$v}";
        }

        if (\is_array($cond)) {
            return 'in.(' . implode(',', array_map([self::class, 'scalar'], $cond)) . ')';
        }

        return 'eq.' . self::scalar($cond);
    }

    private static function scalar(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        // `['op' => 'is', 'value' => null]` is PostgREST's `is.null`; an empty
        // string here sent `is.`, which PostgREST refuses. Same as the TS runtime.
        if ($v === null) {
            return 'null';
        }

        return (string) $v;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    public static function matches(array $row, array $where): bool
    {
        foreach ($where as $col => $cond) {
            $val = $row[$col] ?? null;
            if (\is_array($cond) && isset($cond['op'])) {
                if (! self::compare($val, (string) $cond['op'], $cond['value'] ?? null)) {
                    return false;
                }
            } elseif (\is_array($cond)) {
                if (! \in_array($val, $cond, true)) {
                    return false;
                }
            } elseif ($val !== $cond) {
                return false;
            }
        }

        return true;
    }

    private static function compare(mixed $a, string $op, mixed $b): bool
    {
        return match ($op) {
            'eq', 'is' => $a === $b,
            'neq' => $a !== $b,
            'gt' => $a > $b,
            'gte' => $a >= $b,
            'lt' => $a < $b,
            'lte' => $a <= $b,
            'in' => \is_array($b) && \in_array($a, $b, true),
            'like', 'ilike' => self::likeMatch((string) $a, (string) $b, $op === 'ilike'),
            default => false,
        };
    }

    private static function likeMatch(string $a, string $pattern, bool $insensitive): bool
    {
        $re = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/' . ($insensitive ? 'i' : '');

        return (bool) preg_match($re, $a);
    }
}
