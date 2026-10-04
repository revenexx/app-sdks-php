<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests\Support;

/**
 * Stands in for the platform client's exception (the PHP `revenexx/sdk`): its
 * code is the HTTP status, `getResponse()` the answering App's body verbatim,
 * and its message whatever that body named `message`.
 */
final class PlatformException extends \Exception
{
    public ?string $app = null;

    public function __construct(?string $message, int $code = 0, private readonly string $type = '', private readonly ?string $response = null)
    {
        parent::__construct($message ?? '', $code);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    /**
     * Build what a caller would have caught, as a parity-fixture case describes it.
     *
     * @param array<string, mixed> $t
     */
    public static function thrownFrom(array $t): \Throwable
    {
        if ($t['kind'] === 'http') {
            return new \Revenexx\AppSdk\HttpError($t['status'], $t['message'], $t['details'] ?? null, $t['code'] ?? null);
        }
        if ($t['kind'] === 'crash') {
            return new \TypeError($t['message']);
        }
        $e = new self($t['message'] ?? null, $t['status'] ?? 0, '', $t['response'] ?? '');
        $e->app = $t['app'] ?? null;

        return $e;
    }
}
