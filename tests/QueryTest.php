<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;

final class QueryTest extends TestCase
{
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
