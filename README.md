# revenexx/app-sdk (PHP)

The revenexx App SDK for PHP, the counterpart of `@revenexx/app-sdk` for Apps
running on the open-runtimes PHP runtime. It has the same pieces as the JavaScript
package:

- a typed data client with swappable adapters: `mock`, `remote` and `runtime` (ADR-0013 / ADR-0057)
- a router with one-line CRUD and the same list-filter rules
- the settings reader and the acting person

The generated `Db.php` from `revenexx apps generate --target php` stands on it.

What both runtimes promise, and the one fixture that holds them to it, is in
[`specs/`](../../specs/README.md), in particular `runtime-parity.md`.

## A PHP App function

```php
<?php
// src/main.php — loaded once per process by the runtime
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Router\RouteContext;
use Revenexx\AppSdk\Settings;
use Acme\MyApp\Db;

require __DIR__ . '/../vendor/autoload.php';

// Module scope: the cache outlives the request.
$settings = new Settings(['app' => 'markets', 'defaults' => ['auto_publish' => false]]);

return static function ($context) use ($settings) {
    // Per request: the runtime adapter carries the caller's own identity.
    $client = new Client(AdapterRegistry::create('runtime', ['context' => $context]), Db::entities());
    $app = new App(['name' => 'markets', 'settings' => $settings]);

    Crud::mount($app, $client, $client->entity('markets'), ['path' => '/markets']);
    $app->get('/markets/defaults', static fn (RouteContext $c) => $c->json([
        'auto_publish' => $c->settings()['auto_publish'],
        'contact' => $c->actor->contact,
    ]));

    return $app->handle($context);
};
```

The reference App is `examples/sample-app/php`: `Db.php` (generated), `app.php`
and `main.php`. It runs as a smoke test in this package's suite.

**Answer through `$c->json()`, never `$context->res->json()`.** The runtime's own
`json()` encodes with `JSON_FORCE_OBJECT`, which turns every list into an object
(`{"0":…}`). The router writes JSON through `send()` for that reason.

## Errors

Every refusal is an `HttpError`. It carries a `status`, a stable `errorCode` (the
same codes as the JavaScript package) and a message that is safe to show. The router
turns it into the answer. Anything else a handler throws becomes
`500 {"error":"internal error","code":"internal_error"}`: its message is logged and
never returned.

PostgREST refusals are mapped as in JavaScript: a duplicate is `409
unique_violation`, a missing parent `400 foreign_key_violation`, and so on. No table
or constraint name reaches the caller.

## Not offered in PHP

- **Tracing.** The open-runtimes PHP image carries no OpenTelemetry SDK.
- **`undefined` conditions.** PHP has no `undefined`, so there is nothing to refuse.

## Install

Not published to a registry yet. No workflow releases it, and Composer cannot read
this monorepo's `@revenexx/app-sdk@x.y.z` tags. Publishing, the PHP runtime on the
platform, deploy and scaffold are
[RAD-190](https://linear.app/revenexx/issue/RAD-190). Until then, use it from a
checkout through a Composer `path` repository:

```json
{
  "repositories": [{ "type": "path", "url": "../app-sdks/php/sdk" }],
  "require": { "revenexx/app-sdk": "*@dev" }
}
```

## Tests

```bash
composer install
composer test
```
