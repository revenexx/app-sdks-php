<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Map a non-2xx PostgREST response to an {@see HttpError} whose message is safe
 * to return — the mirror of packages/sdk/src/adapters/postgrest-error.ts. A
 * duplicate is a 409, a missing parent a 400, a malformed value a 400; nothing
 * the caller sees names a table, column or constraint. The raw body travels on
 * `serverDetail` for the logs only.
 */
final class PostgrestError
{
    private const BY_SQLSTATE = [
        '23505' => [409, 'unique_violation', 'a record with this value already exists'],
        '23P01' => [409, 'exclusion_violation', 'a record conflicts with an existing one'],
        '23502' => [400, 'not_null_violation', 'a required value is missing'],
        '23514' => [400, 'check_violation', 'a value is not allowed here'],
        '22P02' => [400, 'invalid_value', 'a value in the request has the wrong format'],
    ];

    private const CLIENT_STATUS = [
        400 => 'the request could not be processed',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not found',
        405 => 'method not allowed',
        406 => 'the requested representation is not available',
        409 => 'the request conflicts with the current state of the data',
        413 => 'the request is too large',
        415 => 'unsupported media type',
        422 => 'the request could not be processed',
        429 => 'too many requests',
    ];

    public static function map(int $status, string $body, string $method, ?string $table = null): HttpError
    {
        $pg = self::parse($body);
        [$mappedStatus, $code, $message] = self::classify($status, $pg, $method);

        return new HttpError($mappedStatus, $message, null, $code, [
            'source' => 'postgrest',
            'status' => $status,
            'method' => $method,
            'table' => $table,
            'sqlstate' => $pg['code'] ?? null,
            'message' => $pg['message'] ?? ($body !== '' ? $body : null),
            'details' => $pg['details'] ?? null,
            'hint' => $pg['hint'] ?? null,
        ]);
    }

    /**
     * PostgREST answers an offset past the last row with `416 PGRST103`. That is
     * a legitimate request — the page after the last one — so the caller gets an
     * empty page with the true total, read from the error details.
     */
    public static function rangeNotSatisfiable(int $status, string $body): ?int
    {
        if ($status !== 416) {
            return null;
        }
        $pg = self::parse($body);
        $text = ($pg['details'] ?? '') . ' ' . ($pg['message'] ?? '');

        return preg_match('/there are only (\d+) rows/i', (string) $text, $m) ? (int) $m[1] : 0;
    }

    /** @return array<string, mixed> */
    private static function parse(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $pg
     * @return array{int, string, string}
     */
    private static function classify(int $status, array $pg, string $method): array
    {
        $sqlstate = \is_string($pg['code'] ?? null) ? $pg['code'] : '';
        if ($sqlstate === '23503') {
            $blocked = [409, 'foreign_key_violation', 'this record is still referenced by other records'];
            $missing = [400, 'foreign_key_violation', 'a referenced record does not exist'];
            $text = ($pg['details'] ?? '') . ' ' . ($pg['message'] ?? '');
            if (preg_match('/is still referenced from/i', (string) $text)) {
                return $blocked;
            }
            if (preg_match('/is not present in table/i', (string) $text)) {
                return $missing;
            }

            return strtoupper($method) === 'DELETE' ? $blocked : $missing;
        }
        if (isset(self::BY_SQLSTATE[$sqlstate])) {
            return self::BY_SQLSTATE[$sqlstate];
        }
        // Class 22 is Postgres' "data exception" family — always bad input.
        if (str_starts_with($sqlstate, '22')) {
            return [400, 'invalid_value', 'a value in the request has the wrong format'];
        }
        if (isset(self::CLIENT_STATUS[$status])) {
            return [$status, 'bad_request', self::CLIENT_STATUS[$status]];
        }

        return [500, 'data_error', 'the data service could not complete the request'];
    }
}
