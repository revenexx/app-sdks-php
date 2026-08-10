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

Install (via packistry):

```bash
composer require revenexx/app-sdk
```

## Tests

```bash
composer install
composer test
```
