<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * A refusal, whichever side raised it — the counterpart of `refusalOf` in the
 * JavaScript SDK.
 *
 * Reads this App's own {@see HttpError}, or the exception the platform client
 * throws when another App refused a call: its integer code is the HTTP status
 * and `getResponse()` is that App's body verbatim. Null for anything that is not
 * a refusal (a crash, a network fault) — a question that could not be answered
 * is not the far side being down.
 */
final class Refusal
{
    private function __construct(
        public readonly int $status,
        public readonly ?string $code,
        public readonly string $message,
        public readonly mixed $details,
        public readonly ?string $app,
    ) {
    }

    public static function of(mixed $err): ?self
    {
        if ($err instanceof HttpError) {
            return new self($err->status, $err->errorCode, $err->getMessage(), $err->details, null);
        }
        if (! $err instanceof \Throwable || ! method_exists($err, 'getResponse')) {
            return null;
        }
        $status = $err->getCode();
        $response = $err->getResponse();
        if (! \is_int($status) || $status < 400 || $status > 599 || ! \is_string($response)) {
            return null;
        }
        $body = json_decode($response !== '' ? $response : '{}', true);
        $body = \is_array($body) && ! array_is_list($body) ? $body : [];
        $str = static fn (mixed $v): ?string => \is_string($v) && $v !== '' ? $v : null;
        $type = method_exists($err, 'getType') ? $err->getType() : null;
        $app = property_exists($err, 'app') ? $err->app : null;

        return new self(
            $status,
            $str($body['code'] ?? null) ?? $str($body['type'] ?? null) ?? $str($type),
            $str($body['error'] ?? null) ?? $str($body['message'] ?? null) ?? $str($err->getMessage()) ?? 'the call was refused',
            $body['details'] ?? null,
            $str($app),
        );
    }
}
