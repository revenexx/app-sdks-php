<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * The one error representation every layer of the PHP SDK agrees on — the
 * mirror of the TS runtime's `HttpError` (packages/sdk/src/errors.ts).
 *
 * - `getMessage()`, `$errorCode` and `$details` are the client-facing payload:
 *   always safe, never derived from a database error body.
 * - `$serverDetail` is the raw diagnosis. The router logs it and drops it; it is
 *   never serialised into a response.
 *
 * The stable machine-readable code is `$errorCode`, not `getCode()`: PHP's
 * exception code is an integer and final.
 */
class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly mixed $details = null,
        public readonly ?string $errorCode = null,
        public readonly mixed $serverDetail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'not found', ?string $code = 'not_found'): self
    {
        return new self(404, $message, null, $code);
    }

    public static function badRequest(string $message = 'bad request', mixed $details = null, ?string $code = 'bad_request'): self
    {
        return new self(400, $message, $details, $code);
    }

    public static function forbidden(string $message = 'forbidden', ?string $code = 'forbidden'): self
    {
        return new self(403, $message, null, $code);
    }

    public static function conflict(string $message = 'conflict', ?string $code = 'conflict'): self
    {
        return new self(409, $message, null, $code);
    }

    /**
     * The response body a caller sees: `error`, plus `code` and `details` when
     * set. `serverDetail` is deliberately absent.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return array_filter(
            ['error' => $this->getMessage(), 'code' => $this->errorCode, 'details' => $this->details],
            static fn (mixed $v): bool => $v !== null,
        );
    }
}
