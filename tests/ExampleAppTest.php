<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Settings;
use Revenexx\AppSdk\Tests\Support\FakeContext;
use Revenexx\SampleApp\Db;

/**
 * The reference App (examples/sample-app/php) assembled the way main.php does
 * it, on the mock adapter: generated client, router, CRUD and settings
 * together. A smoke test of the whole PHP surface, not a promise of its own.
 */
final class ExampleAppTest extends TestCase
{
    public function testTheReferenceAppAnswersThroughTheWholeStack(): void
    {
        $build = require __DIR__ . '/../../../examples/sample-app/php/app.php';
        $client = new Client(AdapterRegistry::create('mock', ['seed' => ['greetings' => []]]), Db::entities());
        $settings = new Settings([
            'app' => 'sample-app',
            'defaults' => ['default_greeting' => 'Hello'],
            'env' => [],
            'transport' => static fn (): array => ['status' => 200, 'body' => '{"settings":{"default_greeting":"Moin"},"masked":[]}'],
        ]);
        $app = $build($client, $settings);

        $created = FakeContext::call($app, 'POST', '/greetings', ['body' => ['name' => 'Max']]);
        $this->assertSame([201, 'Hello', 'en'], [$created['status'], $created['data']['message'], $created['data']['locale']]);
        $this->assertSame(1, FakeContext::call($app, 'GET', '/greetings', ['query' => 'locale=en'])['data']['page']['total']);
        $this->assertSame(400, FakeContext::call($app, 'GET', '/greetings', ['query' => 'organization_id=o1'])['status']);
        $this->assertSame(
            ['greeting' => 'Moin, c1'],
            FakeContext::call($app, 'GET', '/greetings/hello', ['headers' => ['x-revenexx-contact' => 'c1']])['data'],
        );
    }
}
