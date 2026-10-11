<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * An adapter that can reach the event bus.
 *
 * Separate from {@see Adapter} rather than a method on it, because not every
 * backend can publish and PHP interfaces have no optional members — the JavaScript SDK
 * runtime expresses the same thing with an optional `emit` on `Adapter`. An
 * adapter that does not implement this says so at the call site instead of
 * pretending the event went out.
 */
interface EmitsEvents
{
    /**
     * @param array<string, mixed>                                                $data
     * @param array{topicId?: ?string, metadata?: array<string, mixed>, id?: ?string} $options
     * @return array{id: string, topic: string}
     */
    public function emit(EmitTarget $target, array $data = [], array $options = []): array;
}
