# revenexx App SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/revenexx/app-sdk.svg)](https://packagist.org/packages/revenexx/app-sdk)

Build revenexx Apps in PHP. The SDK gives an App function everything it needs to
answer requests on the revenexx platform:

- **A typed data client** for the App's own records, with the same code running
  against in-memory fixtures in a test and against the platform's data plane in
  production.
- **A router** with one-line CRUD routes, safe error answers and stable error codes.
- **The App's settings** as the merchant configured them, per market.
- **Who is acting** behind a request.
- **The App's own events**, fired by name.

It is the PHP counterpart of the JavaScript SDK
[`@revenexx/app-sdk`](https://www.npmjs.com/package/@revenexx/app-sdk). The two
behave the same way, call for call.

## Requirements

- PHP 8.2 or newer, with `ext-curl` and `ext-json`
- The [revenexx CLI](https://www.npmjs.com/package/@revenexx/cli) to generate the typed client (Node.js 18+)

## Install

```bash
composer require revenexx/app-sdk
```

## How an App is put together

An App declares its data in two files, and the CLI turns them into a typed client:

| File | Declares |
|---|---|
| `schema.json` | the App's entities and their columns |
| `manifest.json` | what the App may do with them (`read`, `create`, `update`, `delete`), its events and its settings |

```bash
npx @revenexx/cli apps generate --target php --out ./src --namespace 'Acme\MyApp'
```

This writes `src/Db.php`. It holds one typed repository and one row class per entity,
plus `Db::entities()`, which lists the entities for the SDK. Regenerate it whenever
`schema.json` or `manifest.json` changes; never edit it by hand.

## Quick start: a function

The runtime loads your entrypoint once per process and calls the function it returns
for every request.

```php
<?php
// src/main.php
declare(strict_types=1);

use Acme\MyApp\Db;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Router\RouteContext;
use Revenexx\AppSdk\Settings;

require __DIR__ . '/../vendor/autoload.php';

// Built once per process, so its cache outlives a single request.
$settings = new Settings(['app' => 'markets', 'defaults' => ['auto_publish' => false]]);

return static function ($context) use ($settings) {
    // Built per request: the `runtime` adapter acts with the identity of the
    // request it serves.
    $client = new Client(AdapterRegistry::create('runtime', ['context' => $context]), Db::entities());
    // If the manifest declares events the App fires itself, pass them too:
    // new Client($adapter, Db::entities(), Db::EMIT_FN, Db::declaredEvents())
    $app = new App(['name' => 'markets', 'settings' => $settings]);

    // GET/POST /markets, GET/PUT/DELETE /markets/{id}
    Crud::mount($app, $client, $client->entity('markets'), ['path' => '/markets']);

    $app->get('/markets/defaults', static fn (RouteContext $c) => $c->json([
        'auto_publish' => $c->settings()['auto_publish'],
    ]));

    return $app->handle($context);
};
```

**Answer through `$c->json()`, not `$context->res->json()`.** The runtime's own
`json()` encodes every list as an object (`{"0": …}`). The router writes JSON itself
for that reason.

## The data client

`Client` reaches the App's records. The generated `Db` wraps the same calls with
typed repositories (`$db->markets->list(...)`); `Client` takes an `Entity` and works
with plain arrays.

```php
$markets = $client->entity('markets');

$client->list($markets, ['where' => ['currency' => 'EUR'], 'order' => 'code.asc', 'limit' => 20]);
$client->page($markets, ['limit' => 20, 'offset' => 40]);   // ['items', 'total', 'limit', 'offset']
$client->get($markets, $id);                                 // the row, or null
$client->create($markets, ['code' => 'de', 'currency' => 'EUR']);
$client->update($markets, $id, ['currency' => 'CHF']);
$client->remove($markets, $id);

// Compare-and-set: writes only while the row still matches, in one write.
// Answers the updated row, or null when another writer got there first.
$client->updateIf($orders, $id, ['status' => 'placing'], ['status' => 'placed']);
```

A guard on a number gives an atomic decrement. Read, compute, write only if nobody
wrote in between, and retry when the answer is `null`:

```php
function reserve(Client $client, Entity $budgets, string $id, int $amount): array
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $budget = $client->get($budgets, $id) ?? throw HttpError::notFound('no such budget');
        if ($budget['available'] < $amount) {
            throw HttpError::conflict('not enough budget left');
        }
        $next = $client->updateIf(
            $budgets,
            $id,
            ['available' => $budget['available']],              // the value the decision was based on
            ['available' => $budget['available'] - $amount],
        );
        if ($next !== null) {
            return $next;                                       // our write won
        }
    }
    throw HttpError::conflict('the budget kept changing — try again');
}
```

Two concurrent reservations cannot both spend the same amount. The second one's
condition no longer holds, so it gets `null`, reads again and decides again.

Only the operations the manifest grants are available. Any other call fails with a
`403` and the code `not_permitted`, naming the method.

### Queries

Every read takes the same shape: `where`, `select`, `order`, `limit`, `offset`.

| `where` entry | Matches |
|---|---|
| `['status' => 'published']` | equal to the value |
| `['locale' => ['en', 'de']]` | any of the values |
| `['total' => ['op' => 'gte', 'value' => 100]]` | the comparison: `eq` `neq` `gt` `gte` `lt` `lte` `like` `ilike` `in` `is` |
| `['deleted_at' => null]` | is null |

All entries must hold. An `order` without a direction sorts ascending (`'code'` is
`'code.asc'`).

### Adapters

| Adapter | Use it for |
|---|---|
| `mock` | unit tests: in-memory rows, no network, no setup |
| `remote` | trying your code against a development tenant |
| `runtime` | production: configured from the request the function serves |

```php
AdapterRegistry::create('mock', ['seed' => ['markets' => [['id' => 'm1', 'code' => 'de', 'currency' => 'EUR']]]]);
AdapterRegistry::create('remote', ['endpoint' => 'https://…', 'token' => '…', 'tenant' => '…']);
AdapterRegistry::create('runtime', ['context' => $context]);
```

The mock fills in column defaults and generated keys. It also refuses a filter on a
column the entity does not have, as the platform does. It does not imitate tenant
isolation, unique constraints or foreign keys, so try your App against a development
tenant once before you ship it.

## The router

```php
$app = new App(['name' => 'markets']);
$app->get('/markets/{id}', static fn (RouteContext $c) => $c->json($client->get($markets, $c->params['id'])));
$app->post('/markets/{id}/publish', $handler);
return $app->handle($context);
```

`RouteContext` gives a handler the following:

| | |
|---|---|
| `$c->params` | path parameters |
| `$c->query` | the query string, decoded |
| `$c->body` | the parsed JSON body |
| `$c->tenant` | the tenant the request is for |
| `$c->header($name)` | any request header |
| `$c->actor` | who is acting |
| `$c->settings()` | the App's settings for this request |
| `$c->json($data, $status)` | the answer |
| `$c->log($message)` | the function log |

Routing rules:

- Literal path segments match before parameters, so `/markets/defaults` wins over
  `/markets/{id}`.
- An unknown path answers `404`; a known path with the wrong method answers `405`.
- `GET /` answers the App's name and its routes, as a health check.

### One-line CRUD

`Crud::mount` mounts the five standard routes for an entity:

| Route | Answer |
|---|---|
| `GET /markets` | `{ items, page: { limit, offset, total, returned, hasMore }, filter }` |
| `POST /markets` | the created row, `201` |
| `GET /markets/{id}` | the row, or `404` |
| `PUT /markets/{id}` | the updated row |
| `DELETE /markets/{id}` | `{ deleted: true, id }` |

Options:

- **`only`** mounts a subset, for example `['list', 'get']`.
- **`parent`** nests the routes under a parent:
  `['param' => 'marketId', 'column' => 'market_id', 'entity' => $client->entity('markets')]`
  for `/markets/{marketId}/locales`. Rows are then scoped to the parent in the path;
  nothing in a body or query string reaches another parent's rows. With `entity`
  given, an unknown parent answers `404`.
- **`queryParams`** names extra query keys your App reads itself.

**List filters.** A caller filters the list by exact value: `?currency=EUR`. `limit`,
`offset` and `order` page and sort it. Anything the list cannot apply is refused with
a `400`, never silently ignored:

| Refusal | `code` |
|---|---|
| a key that is not a column and not declared in `queryParams` | `unknown_filter` |
| a value carrying an operator, e.g. `?sku=in.(a,b)` | `unsupported_filter_operator` |
| a filter on a JSON column that is not a whole JSON document | `invalid_value` |

### The trash in one call

An entity with `trash: true` in `schema.json` gets the routes a trash needs from
`Trash::mount`, the same as `mountTrash` in the JavaScript SDK:

```php
$refs = new GatewayCounter();
$trash = Trash::mount($app, $client, $client->entity('organizations'), [
    'path' => '/organizations',
    'children' => [['name' => 'contacts', 'entity' => $client->entity('contacts'), 'column' => 'organization_id']],
    'guard' => fn (array $org) => $org['is_default'] ? ['reason' => 'the default organization stays'] : null,
    'blockers' => fn (array $org, mixed $c) => [
        $refs->blocker($c, ['path' => '/v1/orders/open_orders', 'where' => ['organization_id' => $org['id']], 'reason' => 'open orders']),
    ],
]);
```

- `DELETE /organizations/{id}` on a live row puts it into the trash with its live
  children, as one group (`trash_batch`), recording `deleted_by` / `deleted_by_id`
  from the acting person. `guard` reasons refuse it (`409 trash_blocked`).
- The same `DELETE` on a trashed row deletes it for good: the group's children
  first, then the row. `blockers` reasons refuse it (`409 delete_blocked`);
  `canDeleteForGood` can require its own permission (`403 not_permitted`).
- `POST /organizations/{id}/restore` brings back the row and the children of its
  group (`409 parent_in_trash` when its `parent` is in the trash).
- `$trash->purge($caller)` is the scheduled job. It reads `trash_retention_days`
  through the gateway (`GET /v1/audit/settings`, 90 when unset;
  `REVENEXX_AUDIT_SETTINGS_URL` overrides the URL) and deletes for good what is
  older, through the same `blockers`. A blocked row stays, with its reason in the
  result and in `purge_blocked_reason` (`'purgeReasonColumn' => false` for none).
  If the retention cannot be read, the run is skipped.

A check that throws, and a cross-app count the gateway does not answer, block.

## Errors

Throw an `HttpError` to refuse a request. The router answers it with its status, and
the caller sees only what is safe to show:

```php
use Revenexx\AppSdk\HttpError;

throw HttpError::notFound('no such market');                  // 404 not_found
throw HttpError::badRequest('currency is required');          // 400 bad_request
throw HttpError::forbidden();                                 // 403 forbidden
throw HttpError::conflict('the code is taken');               // 409 conflict
throw new HttpError(409, 'no active budget', ['id' => $id], 'no_active_budget');
```

```json
{ "error": "no active budget", "message": "no active budget", "code": "no_active_budget", "type": "no_active_budget", "details": { "id": "…" } }
```

**Branch on `code`, never on the message.** A code is stable across versions; a
message may be reworded. `message` and `type` repeat `error` and `code` under the
names the revenexx platform client reads.

Anything else a handler throws answers `500 {"error": "internal error", "code":
"internal_error"}`. Its message goes to the function log and never reaches the
caller.

The data client raises `HttpError`s itself:

| Situation | Status | `code` |
|---|---|---|
| an operation the manifest does not grant | 403 | `not_permitted` |
| an update with nothing to update | 400 | `empty_update` |
| `updateIf` without a condition | 400 | `empty_condition` |
| an update whose row is gone | 404 | `not_found` |
| a duplicate value | 409 | `unique_violation` |
| a referenced record that does not exist | 400 | `foreign_key_violation` |
| a record still referenced by others, on delete | 409 | `foreign_key_violation` |
| a required value missing / a check failed | 400 | `not_null_violation` / `check_violation` |
| a value in the wrong format | 400 | `invalid_value` |
| the data plane unavailable | 500 | `data_error` |

No table, column or constraint name ever appears in an answer. The full diagnosis is
on `$e->serverDetail` and in the function log.

### Reading another App's refusal

When your App calls another App and is refused, `Refusal::of()` reads what it said:

```php
use Revenexx\AppSdk\Refusal;

try {
    // … call another App through the revenexx platform client …
} catch (\Throwable $e) {
    $refusal = Refusal::of($e);   // status, code, message, details, app — or null
    if ($refusal?->code === 'no_active_budget') {
        throw new HttpError(409, $refusal->message, null, 'no_budget');
    }
    throw $e;                     // not a refusal: a crash, or the other side is down
}
```

## Settings

`$c->settings()` returns the App's settings for the tenant and market of the request,
resolved by the platform: a market value, else the tenant's value, else the default
from your settings declaration.

```php
$settings = new Settings([
    'app' => 'orders',
    'defaults' => ['auto_reserve' => true],   // used when nothing could be resolved
]);
```

- Reads are cached per tenant, App and market for 60 seconds.
- A settings outage never fails a request: the reader answers with the last values it
  resolved, or with your `defaults`.
- Settings declared sensitive are withheld; `$c->settingsSnapshot()` lists their keys
  under `masked`.

## Who is acting

`$c->actor` reads the identity the platform forwarded with the request:

| | |
|---|---|
| `user`, `email` | the person, when one is known |
| `contact`, `organization` | the customer contact and organization the call is for |
| `permissions`, `holds('orders.approve')` | what that person may do |
| `trust` | `authenticated`, `asserted` or `none` |
| `plane` | `contact`, `operator` or `system` |

**Check `trust` before enforcing a per-person rule.** A contact named by a storefront
server is `asserted`, not proven: `authenticated()` answers `true` only for a person
the platform verified itself. Today the platform verifies no person itself, so treat
every contact as asserted.

## The App's own events

An App declares the events it fires itself in its manifest, for facts with no row
behind them:

```php
$client->events()->emit('stock.low', ['sku' => $sku, 'available' => 3], [
    'topicId' => $sku,
    // A deterministic id makes a retry a no-op instead of a second event.
    'id' => "evt_low_{$sku}_" . date('Ymd'),
]);
```

With the generated client, the same call is `$db->events()->emit(…)`. A `Client`
built by hand needs `Db::EMIT_FN` and `Db::declaredEvents()` passed to its
constructor (see the quick start). An event the manifest does not declare is refused
before anything is sent. On the
`mock` adapter nothing is published; `$client->events()->emitted()` lists what the App
fired, so a test can assert it.

## Testing an App

Build the same routes on the `mock` adapter and run requests through `$app->handle()`,
with a small stand-in for the runtime's context:

```php
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;

$client = new Client(AdapterRegistry::create('mock', ['seed' => ['markets' => []]]), Db::entities());
$app = new App(['name' => 'markets']);
Crud::mount($app, $client, $client->entity('markets'), ['path' => '/markets']);

$context = (object) [
    'req' => (object) [
        'method' => 'POST',
        'path' => '/markets',
        'queryString' => '',
        'query' => [],
        'headers' => ['x-revenexx-tenant' => 'acme'],
        'bodyBinary' => '{"code":"de","currency":"EUR"}',
    ],
    'res' => new class () {
        public function send(string $body, int $status = 200, array $headers = []): array
        {
            return ['body' => $body, 'statusCode' => $status, 'headers' => $headers];
        }
    },
];

$answer = $app->handle($context);   // ['body' => '{"id":"…","code":"de",…}', 'statusCode' => 201, …]
```

Keep the route setup in one function that both `main.php` and your tests call, so the
tests run the routes production runs. For `Settings`, pass a `transport` callable to
answer without a network:

```php
new Settings(['app' => 'markets', 'transport' => fn () => ['status' => 200, 'body' => '{"settings":{"auto_publish":true}}']]);
```

## Not included

- **Tracing.** The JavaScript SDK traces requests with OpenTelemetry; the PHP SDK does
  not.

## Versioning

The SDK follows [semantic versioning](https://semver.org). The public classes and
methods, the answers of the routes it mounts, and the status and `code` of every
refusal are bound: changing any of them needs a new major version. A message may be
reworded in a minor version. See [CHANGELOG.md](CHANGELOG.md).

## Working on the SDK itself

```bash
composer install
composer test
```

A few tests check the SDK against cases it shares with the JavaScript SDK. Those cases
are not part of this package, so these tests are skipped here.

This repository is published from the SDK's main repository and is replaced on every
release, so pull requests opened here cannot be merged. Report problems to revenexx.

## License

MIT — see [LICENSE](LICENSE).
