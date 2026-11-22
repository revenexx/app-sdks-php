<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

/**
 * What an answer leaves out: `tenant_id` — the PHP counterpart of the
 * JavaScript router's `withoutTenantId`.
 *
 * Every record an App reads carries the tenant it belongs to. The caller
 * already named its tenant to reach this data, so the key tells it nothing,
 * and it names the store's partitioning on every public answer. The router
 * leaves it out of every answer a handler gives with `$c->json()`, unless the
 * App is created with `['answerTenantId' => true]`.
 *
 * One pass over the answer. Arrays and objects are walked; a
 * `JsonSerializable` is walked as what it serialises to. An object that loses
 * its last key stays an object (`{}`), never an empty list.
 */
final class Answer
{
    /** The key no answer carries unless the App opts out. */
    public const TENANT_KEY = 'tenant_id';

    public static function withoutTenantId(mixed $value): mixed
    {
        $changed = false;

        return self::walk($value, $changed);
    }

    /** `$value` without the key; `$changed` says whether anything was left out. */
    private static function walk(mixed $value, bool &$changed): mixed
    {
        $changed = false;
        if (\is_array($value)) {
            return self::fromArray($value, $changed);
        }
        if ($value instanceof \JsonSerializable) {
            $out = self::walk($value->jsonSerialize(), $changed);

            return $changed ? $out : $value;
        }
        if (\is_object($value)) {
            // What json_encode reads of an object: its public properties.
            $vars = get_object_vars($value);
            if ($vars === []) {
                return $value;
            }
            $out = self::fromArray($vars, $changed);

            return $changed ? (object) $out : $value;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private static function fromArray(array $value, bool &$changed): mixed
    {
        $keyed = \array_key_exists(self::TENANT_KEY, $value);
        if ($keyed) {
            unset($value[self::TENANT_KEY]);
            $changed = true;
        }
        foreach ($value as $k => $item) {
            if (\is_array($item) || \is_object($item)) {
                $sub = false;
                $next = self::walk($item, $sub);
                if ($sub) {
                    $value[$k] = $next;
                    $changed = true;
                }
            }
        }
        // `{"tenant_id": …}` decoded to an array is an object; without its key
        // it would encode as `[]`.
        return $keyed && $value === [] ? new \stdClass() : $value;
    }
}
