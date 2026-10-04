<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\CountsRows;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;

/**
 * The five REST routes for one entity, in one call — the mirror of `mountCrud`
 * in packages/sdk/src/router/crud.ts, with the same list rules:
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
        $parent = $options['parent'] ?? null;

        if (\in_array('list', $enabled, true)) {
            $app->get($base, static function (RouteContext $c) use ($client, $entity, $options, $columns, $parent, $defaultLimit, $maxLimit): mixed {
                // Refuse before any round trip — including the parent lookup.
                self::assertKnownKeys($c->query, $columns, $options['queryParams'] ?? []);
                $where = [];
                foreach ($c->query as $k => $v) {
                    if (! \array_key_exists($k, $columns)) {
                        continue; // reserved and App-declared keys are not filters
                    }
                    self::assertNoOperator((string) $k, $v);
                    if (self::isJsonColumn($columns[$k])) {
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
            $app->post($base, static function (RouteContext $c) use ($client, $entity, $parent): mixed {
                self::assertParentExists($c, $client, $parent);

                return $c->json($client->create($entity, [...$c->body, ...self::parentWhere($c, $parent)]), 201);
            });
        }

        if (\in_array('get', $enabled, true)) {
            $app->get($idTemplate, static function (RouteContext $c) use ($client, $entity, $parent, $idParam): mixed {
                return $c->json(self::fetchOwned($c, $client, $entity, $parent, $idParam)[1]);
            });
        }

        if (\in_array('update', $enabled, true)) {
            $app->put($idTemplate, static function (RouteContext $c) use ($client, $entity, $parent, $idParam): mixed {
                [$id] = self::fetchOwned($c, $client, $entity, $parent, $idParam);
                if ($c->body === []) {
                    throw HttpError::badRequest(Client::EMPTY_UPDATE_MESSAGE, null, 'empty_update');
                }

                // Never let a body overwrite the nested-parent FK.
                return $c->json($client->update($entity, $id, [...$c->body, ...self::parentWhere($c, $parent)]));
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
     * @param list<string>          $extra
     */
    private static function assertKnownKeys(array $query, array $columns, array $extra): void
    {
        $known = static fn (string $k): bool => \array_key_exists($k, $columns)
            || \in_array($k, self::RESERVED_LIST_QUERY_KEYS, true)
            || \in_array($k, $extra, true);
        $unknown = array_values(array_filter(array_map('strval', array_keys($query)), static fn (string $k): bool => ! $known($k)));
        if ($unknown === []) {
            return;
        }
        $allowed = array_values(array_unique([...array_map('strval', array_keys($columns)), ...self::RESERVED_LIST_QUERY_KEYS, ...$extra]));
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
