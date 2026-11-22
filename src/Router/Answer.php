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
 * A stored document is the App's content, not a record: the value under a key
 * named in `$documents` — the `json` / `jsonb` columns of the entities the
 * request reached ({@see \Revenexx\AppSdk\AnswerScope}) — is answered as
 * stored, `tenant_id` and all.
 *
 * One pass over the answer. Arrays and objects are walked; a
 * `JsonSerializable` as what it serialises to, an `ArrayObject` /
 * `ArrayIterator` as its storage, any other object as its public properties.
 * An object that loses its key stays an object — `{}`, or `{"0": …}` when
 * what is left would read as a list — as in JavaScript.
 */
final class Answer
{
    /** The key no answer carries unless the App opts out. */
    public const TENANT_KEY = 'tenant_id';

    /** @param list<string> $documents keys whose value is a stored document, left as given */
    public static function withoutTenantId(mixed $value, array $documents = []): mixed
    {
        $changed = false;

        return self::walk($value, array_fill_keys($documents, true), $changed);
    }

    /**
     * `$value` without the key; `$changed` says whether anything was left out.
     *
     * @param array<string, true> $docs
     */
    private static function walk(mixed $value, array $docs, bool &$changed): mixed
    {
        $changed = false;
        if (\is_array($value)) {
            return self::fromArray($value, $docs, $changed);
        }
        if (! \is_object($value)) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            $out = self::walk($value->jsonSerialize(), $docs, $changed);

            return $changed ? $out : $value;
        }
        // What json_encode reads of an object: an ArrayObject's storage, else
        // its public properties.
        $vars = $value instanceof \ArrayObject || $value instanceof \ArrayIterator ? $value->getArrayCopy() : get_object_vars($value);
        if ($vars === []) {
            return $value;
        }
        $out = self::fromArray($vars, $docs, $changed, true);

        return $changed ? (\is_array($out) ? (object) $out : $out) : $value;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true> $docs
     */
    private static function fromArray(array $value, array $docs, bool &$changed, bool $object = false): mixed
    {
        $keyed = \array_key_exists(self::TENANT_KEY, $value);
        if ($keyed) {
            unset($value[self::TENANT_KEY]);
            $changed = true;
        }
        foreach ($value as $k => $item) {
            if ((\is_array($item) || \is_object($item)) && ! isset($docs[(string) $k])) {
                $sub = false;
                $next = self::walk($item, $docs, $sub);
                if ($sub) {
                    $value[$k] = $next;
                    $changed = true;
                }
            }
        }
        // An array that held `tenant_id` was an object; without the key it must
        // not encode as a list (`[]`, `["a"]`).
        return ($keyed || $object) && array_is_list($value) ? (object) $value : $value;
    }
}
