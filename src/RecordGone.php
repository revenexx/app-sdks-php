<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Thrown by {@see RoundTrip::update()} when the record it compares a write
 * against is not there. `Client::update()` answers it 404, `updateIf()` null —
 * the answers each gives for a missing row anyway.
 */
final class RecordGone extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('the record is not there');
    }
}
