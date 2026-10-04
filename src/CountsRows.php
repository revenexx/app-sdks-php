<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * An adapter that can report how many rows match beside the page it returns.
 *
 * Separate from {@see Adapter} for the reason {@see UpdatesConditionally} is:
 * PHP interfaces have no optional members, and the TS runtime makes `page`
 * optional on `Adapter`.
 */
interface CountsRows
{
    /**
     * @param array<string, mixed> $query { where, select, order, limit, offset }
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: ?int, offset: ?int}
     */
    public function page(Entity $entity, array $query = []): array;
}
