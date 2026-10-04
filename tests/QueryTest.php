<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;

final class QueryTest extends TestCase
{
    // query-language AC-6 — A query serialises to the data plane's own querystring
    #[Group('spec:query-language:AC-6')]
    #[Group('spec:query-language')]
    public function testToParamsSerialisesAPostgrestQuerystring(): void
    {
        $qs = Query::toParams([
            'where' => [
                'status' => 'published',
                'n' => ['op' => 'gte', 'value' => 5],
                'locale' => ['en', 'de'],
            ],
            'select' => ['id', 'name'],
            'order' => 'created_at.desc',
            'limit' => 10,
            'offset' => 20,
        ]);

        parse_str($qs, $parsed);
        $this->assertSame('eq.published', $parsed['status']);
        $this->assertSame('gte.5', $parsed['n']);
        $this->assertSame('in.(en,de)', $parsed['locale']);
        $this->assertSame('id,name', $parsed['select']);
        $this->assertSame('created_at.desc', $parsed['order']);
        $this->assertSame('10', $parsed['limit']);
        $this->assertSame('20', $parsed['offset']);
    }

    // query-language AC-4 — An unknown operator is refused
    #[Group('spec:query-language:AC-4')]
    #[Group('spec:query-language')]
    public function testAnUnknownOperatorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("query: unsupported operator 'cs'");
        Query::toParams(['where' => ['tags' => ['op' => 'cs', 'value' => '{a}']]]);
    }

    // runtime-adapter AC-4 — Another backend plugs in without changing App code
    #[Group('spec:runtime-adapter:AC-4')]
    #[Group('spec:runtime-adapter')]
    public function testAnotherBackendPlugsIntoTheRegistry(): void
    {
        $mock = new \Revenexx\AppSdk\Adapter\MockAdapter(['greetings' => [['id' => '1', 'name' => 'Ada']]]);
        AdapterRegistry::register('parity-null', static fn (array $config): Adapter => $mock);

        $this->assertContains('parity-null', AdapterRegistry::kinds());
        $this->assertContains('mock', AdapterRegistry::kinds());
        $this->assertContains('remote', AdapterRegistry::kinds());

        $entity = new Entity('greetings', 'g', 'id', [], ['list']);
        $db = new Client(AdapterRegistry::create('parity-null'), [$entity->name => $entity]);
        $this->assertSame('Ada', $db->list($entity)[0]['name']);
    }

    // runtime-adapter AC-3 — The remote adapter sends what it was configured with
    #[Group('spec:runtime-adapter:AC-3')]
    #[Group('spec:runtime-adapter')]
    public function testRemoteAdapterBuildsRequestsAgainstInjectedTransport(): void
    {
        $calls = [];
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'body');

            return ['status' => 200, 'body' => json_encode([['id' => 'r1', 'name' => 'Max']])];
        };

        $entity = new Entity('greetings', 'revenexx__sample_app__greetings', 'id', [], ['list', 'create']);
        $remote = new RemoteAdapter('http://pgrst:3000/', 'tok', 'acme', $transport);

        $remote->list($entity, ['where' => ['locale' => 'en'], 'limit' => 5]);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('http://pgrst:3000/revenexx__sample_app__greetings?locale=eq.en&limit=5', $calls[0]['url']);
        $this->assertContains('Authorization: Bearer tok', $calls[0]['headers']);
        $this->assertContains('X-Revenexx-Tenant: acme', $calls[0]['headers']);

        $remote->create($entity, ['name' => 'Max']);
        $this->assertSame('POST', $calls[1]['method']);
        $this->assertSame('Max', json_decode((string) $calls[1]['body'], true)['name']);
    }
}
