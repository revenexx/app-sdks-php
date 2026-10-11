<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * An adapter that can update a row only if it still matches a condition, in
 * one atomic write (compare-and-set).
 *
 * Separate from {@see Adapter} for the reason {@see EmitsEvents} is: PHP
 * interfaces have no optional members, and the JavaScript SDK makes `updateIf`
 * optional on `Adapter`. The client cannot emulate it as a read followed by an
 * update — that is the race it exists to close — so an adapter without it
 * makes {@see Client::updateIf()} fail rather than degrade.
 */
interface UpdatesConditionally
{
    /**
     * @param array<string, mixed> $where every entry must hold, same shape as a query's `where`
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|null the updated row, or null when no row matched
     */
    public function updateIf(Entity $entity, int|string $id, array $where, array $patch): ?array;
}
