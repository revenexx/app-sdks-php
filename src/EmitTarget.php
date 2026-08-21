<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/** The generated routine to call, and which declared event is being fired. */
final class EmitTarget
{
    public function __construct(
        /** The generated per-App routine, e.g. `revenexx__inventories__emit`. */
        public readonly string $fn,
        /** The declared event name, e.g. `stock.low`. */
        public readonly string $name,
        public readonly EventDescriptor $descriptor,
    ) {
    }
}
