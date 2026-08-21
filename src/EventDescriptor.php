<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * One event the App may fire itself — a `manifest.events.emits[]` entry
 * declared `trigger: "app"`. Generated into the App's client beside the entity
 * descriptors. Mirrors `EventDescriptor` in the TS runtime.
 */
final class EventDescriptor
{
    /**
     * @param string                    $topic  vendor-scoped topic the platform publishes
     * @param array<string, mixed>|null $sample declared example payload, as the catalog shows it
     * @param array<string, mixed>|null $schema optional JSON Schema for `data`
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?array $sample = null,
        public readonly ?array $schema = null,
    ) {
    }
}
