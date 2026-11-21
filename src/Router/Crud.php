<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\CountsRows;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\RoundTrip;

/**
 * The five REST routes for one entity, in one call — the counterpart of
 * `mountCrud` in the JavaScript SDK, with the same list rules:
 *
 *   Crud::mount($app, $client, $client->entity('locales'), [
 *       'path' => '/markets/{marketId}/locales',
 *       'parent' => ['param' => 'marketId', 'column' => 'market_id', 'entity' => $client->entity('markets')],
 *   ]);
 *
 * A list filter that cannot be applied is refused with a 400 naming it — never
 * answered with the unfiltered or the empty list — and before any round trip,
 * the parent lookup included.
 *
 * Options: `path` (required), `columns` (default: the entity's), `queryParams`,
 * `parent` (`param`, `column`, optional `entity` to 404 an unknown parent),
 * `idParam` (default `id`), `defaultLimit` (50), `maxLimit` (200), `only`.
 * The columns the entity's `trash` / `external_id` flags add are list filters
 * as well, whether or not `columns` names them, unless `queryParams` claims the
 * key. On a `trash: true` entity `POST` / `PUT` refuse a body that sets a trash
 * column (`400 read_only_field`): only the trash routes write them; one sent as
 * null — what a live record reads — is dropped.
 *
 * A record read and sent back is an edit ({@see RoundTrip}): `PUT` drops the
 * key, the tenant, the timestamps, the stored generated columns and the
 * columns named in `readOnly` when they repeat the record, refuses them when
 * they do not, and answers the record unchanged when nothing is left. `POST`
 * refuses a value for a key the store assigns and a tenant other than the
 * request's.
 */
final class Crud
{
    /** Query keys the list reads itself. Always accepted, never filters. */
    public const RESERVED_LIST_QUERY_KEYS = ['limit', 'offset', 'order'];

    private const OPERATOR_PREFIX = '/^(?:not|eq|neq|gt|gte|lt|lte|like|ilike|in|is)\./';

    /** @param array<string, mixed> $options */
    public static function mount(App $app, Client $client, Entity $entity, array $options): App
    {
        $base = rtrim((string) $options['path'], '/');
        $idParam = (string) ($options['idParam'] ?? 'id');
        $idTemplate = "{$base}/{{$idParam}}";
        $enabled = $options['only'] ?? ['list', 'get', 'create', 'update', 'delete'];
        $defaultLimit = (int) ($options['defaultLimit'] ?? 50);
        $maxLimit = (int) ($options['maxLimit'] ?? 200);
        $columns = $options['columns'] ?? $entity->columns;
        // The columns the entity's trash / external_id flags add filter like a
        // declared one, so a list accepts every column a write may name.
        $flagColumns = $entity->flagColumns();
        $parent = $options['parent'] ?? null;
        // Columns the App keeps itself: a PUT may repeat them, never change them.
        $readOnly = array_values(array_map('strval', $options['readOnly'] ?? []));

        if (\in_array('list', $enabled, true)) {
            $app->get($base, static function (RouteContext $c) use ($client, $entity, $options, $columns, $flagColumns, $parent, $defaultLimit, $maxLimit): mixed {
                // Refuse before any round trip — including the parent lookup.
                self::assertKnownKeys($c->query, $columns, $flagColumns, $options['queryParams'] ?? []);
                $where = [];
                foreach ($c->query as $k => $v) {
                    $declared = \array_key_exists($k, $columns);
                    // A flag column the App claims in queryParams is the App's key.
                    $flagged = ! $declared && ! \in_array((string) $k, $options['queryParams'] ?? [], true)
                        && \in_array((string) $k, $flagColumns, true);
                    if (! $declared && ! $flagged) {
                        continue; // reserved and App-declared keys are not filters
                    }
                    self::assertNoOperator((string) $k, $v);
                    if ($declared && self::isJsonColumn($columns[$k])) {
                        self::assertJsonFilter((string) $k, $v);
                    }
                    $where[$k] = $v;
                }
                self::assertParentExists($c, $client, $parent);
                // The parent from the path always wins over a query key.
                $where = [...$where, ...self::parentWhere($c, $parent)];
                $limit = min(max(self::int($c->query['limit'] ?? null, $defaultLimit), 1), $maxLimit);
                $offset = max(self::int($c->query['offset'] ?? null, 0), 0);
                $query = ['where' => $where, 'limit' => $limit, 'offset' => $offset];
                if (($c->query['order'] ?? '') !== '') {
                    $query['order'] = $c->query['order'];
                }

                if ($client->adapter() instanceof CountsRows) {
                    $page = $client->page($entity, $query);
                    $items = $page['items'];

                    return $c->json([
                        'items' => $items,
                        'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $page['total'], 'returned' => \count($items), 'hasMore' => $offset + \count($items) < $page['total']],
                        'filter' => (object) $where,
                    ]);
                }
                $items = $client->list($entity, $query);

                return $c->json(['items' => $items, 'page' => ['limit' => $limit, 'offset' => $offset, 'returned' => \count($items)], 'filter' => (object) $where]);
            });
        }

        if (\in_array('create', $enabled, true)) {
            $app->post($base, static function (RouteContext $c) use ($client, $entity, $parent, $columns): mixed {
                // A list is not one record; merged with the parent it would
                // arrive as keys 0, 1, … Refused as itself, before the lookup.
                if (Entity::isList($c->body)) {
                    throw Entity::oneRecordRefusal();
                }
                // A record copied from a read carries its trash columns as
                // null — what a new record holds — so they are dropped; a
                // value is refused.
                $body = $entity->trash ? RoundTrip::withoutNullTrash($c->body) : $c->body;
                $entity->assertNoTrashFields($body);
                // The key the store assigns and the tenant are not the caller's.
                $fields = RoundTrip::create($entity->pk, $columns, $body, $c->tenant);
                self::assertParentExists($c, $client, $parent);

                return $c->json($client->create($entity, [...$fields, ...self::parentWhere($c, $parent)]), 201);
            });
        }

        if (\in_array('get', $enabled, true)) {
            $app->get($idTemplate, static function (RouteContext $c) use ($client, $entity, $parent, $idParam): mixed {
                return $c->json(self::fetchOwned($c, $client, $entity, $parent, $idParam)[1]);
            });
        }

        if (\in_array('update', $enabled, true)) {
            $app->put($idTemplate, static function (RouteContext $c) use ($client, $entity, $parent, $idParam, $columns, $readOnly): mixed {
                if (Entity::isList($c->body)) {
                    throw Entity::oneRecordRefusal();
                }
                // A live record reads its trash columns as null, and an edit
                // form sends the read back: null is dropped, a value refused.
                $body = $entity->trash ? RoundTrip::withoutNullTrash($c->body) : $c->body;
                $entity->assertNoTrashFields($body);
                [$id, $row] = self::fetchOwned($c, $client, $entity, $parent, $idParam);
                // The body as sent: `{}` is a request with nothing in it.
                if ($c->body === []) {
                    throw HttpError::badRequest(Client::EMPTY_UPDATE_MESSAGE, null, 'empty_update');
                }
                // A record sent back repeats what no write may set: dropped
                // when it says what the record says, refused otherwise.
                $fields = RoundTrip::update($entity->pk, $columns, $id, $body, static fn (): array => $row, $readOnly);
                // The parent key comes from the path whatever the body says.
                if ($parent !== null) {
                    unset($fields[$parent['column']]);
                }
                // Nothing left: the record as it stands, no write, no update event.
                if ($fields === []) {
                    return $c->json($row);
                }

                // Never let a body overwrite the nested-parent FK.
                return $c->json($client->update($entity, $id, [...$fields, ...self::parentWhere($c, $parent)]));
            });
        }

        if (\in_array('delete', $enabled, true)) {
            $app->delete($idTemplate, static function (RouteContext $c) use ($client, $entity, $parent, $idParam): mixed {
                [$id] = self::fetchOwned($c, $client, $entity, $parent, $idParam);
                $client->remove($entity, $id);

                return $c->json(['deleted' => true, 'id' => $id]);
            });
        }

        return $app;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>  $columns
     * @param list<string>          $flagColumns
     * @param list<string>          $extra
     */
    private static function assertKnownKeys(array $query, array $columns, array $flagColumns, array $extra): void
    {
        $known = static fn (string $k): bool => \array_key_exists($k, $columns)
            || \in_array($k, $flagColumns, true)
            || \in_array($k, self::RESERVED_LIST_QUERY_KEYS, true)
            || \in_array($k, $extra, true);
        $unknown = array_values(array_filter(array_map('strval', array_keys($query)), static fn (string $k): bool => ! $known($k)));
        if ($unknown === []) {
            return;
        }
        $allowed = array_values(array_unique([...array_map('strval', array_keys($columns)), ...$flagColumns, ...self::RESERVED_LIST_QUERY_KEYS, ...$extra]));
        sort($allowed);
        $quoted = implode(', ', array_map(static fn (string $k): string => "'{$k}'", $unknown));

        throw new HttpError(
            400,
            "unknown list filter {$quoted} — not a column of this resource. A filter that cannot be applied is refused rather than ignored; see details.allowed.",
            ['unknown' => $unknown, 'allowed' => $allowed],
            'unknown_filter',
        );
    }

    private static function assertNoOperator(string $column, string $value): void
    {
        if (! preg_match(self::OPERATOR_PREFIX, $value)) {
            return;
        }
        $op = substr($value, 0, (int) strpos($value, '.') + 1);

        throw new HttpError(
            400,
            "filter '{$column}' carries an operator ('{$op}') — a list filters by exact value only; operators and value lists are not supported.",
            ['parameter' => $column],
            'unsupported_filter_operator',
        );
    }

    private static function isJsonColumn(mixed $def): bool
    {
        return (bool) preg_match('/^jsonb?\b/i', (string) (\is_array($def) ? ($def['type'] ?? '') : ''));
    }

    private static function assertJsonFilter(string $column, string $value): void
    {
        if ($value !== '') {
            try {
                json_decode($value, false, 512, JSON_THROW_ON_ERROR);

                return;
            } catch (\JsonException) {
                // fall through to the refusal
            }
        }

        throw new HttpError(
            400,
            "filter '{$column}' must be a whole JSON document, serialized exactly as stored and compared for equality — not a path or containment query. This parameter's example in the API contract shows the shape this column holds.",
            null,
            'invalid_value',
        );
    }

    /**
     * @param array{param: string, column: string, entity?: Entity}|null $parent
     * @return array<string, string>
     */
    private static function parentWhere(RouteContext $c, ?array $parent): array
    {
        if ($parent === null) {
            return [];
        }
        $value = $c->params[$parent['param']] ?? '';
        if ($value === '') {
            throw HttpError::badRequest("missing path parameter '{$parent['param']}'", null, 'missing_parameter');
        }

        return [$parent['column'] => $value];
    }

    /** @param array{param: string, column: string, entity?: Entity}|null $parent */
    private static function assertParentExists(RouteContext $c, Client $client, ?array $parent): void
    {
        if ($parent === null || ! isset($parent['entity'])) {
            return;
        }
        $value = $c->params[$parent['param']] ?? '';
        if ($value === '') {
            throw HttpError::badRequest("missing path parameter '{$parent['param']}'", null, 'missing_parameter');
        }
        if ($client->get($parent['entity'], $value) === null) {
            throw HttpError::notFound();
        }
    }

    /**
     * @param array{param: string, column: string, entity?: Entity}|null $parent
     * @return array{string, array<string, mixed>}
     */
    private static function fetchOwned(RouteContext $c, Client $client, Entity $entity, ?array $parent, string $idParam): array
    {
        $id = $c->params[$idParam] ?? '';
        if ($id === '') {
            throw HttpError::badRequest("missing path parameter '{$idParam}'", null, 'missing_parameter');
        }
        $row = $client->get($entity, $id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        if ($parent !== null && (string) ($row[$parent['column']] ?? '') !== (string) ($c->params[$parent['param']] ?? '')) {
            throw HttpError::notFound();
        }

        return [$id, $row];
    }

    private static function int(mixed $value, int $default): int
    {
        return \is_string($value) && preg_match('/^-?\d+/', $value, $m) ? (int) $m[0] : $default;
    }
}
