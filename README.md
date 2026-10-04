# revenexx/app-sdk (PHP)

Typed PostgREST data client for revenexx Apps — the PHP runtime that the
generated `Db.php` (from `revenexx apps generate --target php`) depends on.
Swappable adapters (`mock` | `remote`) mirror the JS SDK (ADR-0013 / ADR-0057).

```php
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\AdapterRegistry;

$greetings = new Entity('greetings', 'revenexx__sample_app__greetings', 'id', $columns, ['list', 'create']);
$client = new Client(AdapterRegistry::create('mock'), ['greetings' => $greetings]);

$row = $client->create($greetings, ['name' => 'Max']);   // defaults applied
```

Not published to a registry yet (no workflow releases it, and it has no version
tags of its own — see RAD-189). Use it from a checkout through a Composer `path`
repository:

```json
{
  "repositories": [{ "type": "path", "url": "../app-sdks/php/sdk" }],
  "require": { "revenexx/app-sdk": "*@dev" }
}
```

It is a data client only — no router, settings reader, runtime adapter, `page()`
or error mapping, which the JavaScript package has. `specs/runtime-parity.md`
says what the two runtimes agree on.

## Tests

```bash
composer install
composer test
```
