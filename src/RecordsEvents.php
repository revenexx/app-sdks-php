<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * An adapter that records emits instead of publishing them, so a test can
 * assert what an App fired without any infrastructure. Only the mock adapter
 * does this; the TS runtime's optional `emitted()` is the same idea.
 */
interface RecordsEvents
{
    /**
     * @return array<int, array{event: string, topic: string, data: array<string, mixed>, options: array<string, mixed>, result: array{id: string, topic: string}}>
     */
    public function emitted(): array;
}
