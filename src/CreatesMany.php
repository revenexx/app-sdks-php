<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * An adapter that inserts a list of records in one request (a bulk create).
 *
 * Separate from {@see Adapter} for the reason {@see UpdatesConditionally} is:
 * PHP interfaces have no optional members, and the JavaScript SDK makes
 * `createMany` optional on `Adapter`. The client never emulates it as one
 * create per record — that would be several statements where the caller asked
 * for one — so an adapter without it makes {@see Client::createMany()} fail.
 */
interface CreatesMany
{
    /**
     * @param list<array<string, mixed>> $rows a non-empty list of records naming the same fields
     * @return list<array<string, mixed>> the created rows, in order
     */
    public function createMany(Entity $entity, array $rows): array;
}
