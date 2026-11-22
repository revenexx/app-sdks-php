<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Which keys of a request's answer hold a stored document — the PHP
 * counterpart of the JavaScript SDK's answer scope.
 *
 * The router leaves `tenant_id` out of every answer, but a `json` / `jsonb`
 * column is the App's own content: a stored payload may carry a `tenant_id` of
 * its own, and removing it from the answer would let the next GET → PUT delete
 * it for good. The router cannot tell a document from an embedded record by its
 * shape, so the data client tells it: every entity a request reads or writes
 * names its document columns here, and the answer walk answers the value under
 * such a key as stored. The scope is per request: `Router\App` opens one around
 * each handler, and outside a request nothing is noted.
 */
final class AnswerScope
{
    /** @var array<string, true>|null the document column names of the request being handled */
    private static ?array $documents = null;

    /** Open a fresh scope; returns the one it replaces, for {@see self::close()}. */
    public static function open(): ?array
    {
        $previous = self::$documents;
        self::$documents = [];

        return $previous;
    }

    /** @param array<string, true>|null $previous */
    public static function close(?array $previous): void
    {
        self::$documents = $previous;
    }

    /** @return list<string> the document column names noted in the current scope */
    public static function documents(): array
    {
        return array_keys(self::$documents ?? []);
    }

    /** Whether a column definition is a stored document (`json`, `jsonb`, or an array of either). */
    public static function isDocumentColumn(mixed $def): bool
    {
        return \is_array($def) && (bool) preg_match('/^jsonb?\b/i', (string) ($def['type'] ?? ''));
    }

    /**
     * Note the document columns of a column map in the current scope.
     *
     * @param array<string, mixed> $columns
     */
    public static function note(array $columns): void
    {
        if (self::$documents === null) {
            return;
        }
        foreach ($columns as $name => $def) {
            if (self::isDocumentColumn($def)) {
                self::$documents[(string) $name] = true;
            }
        }
    }
}
