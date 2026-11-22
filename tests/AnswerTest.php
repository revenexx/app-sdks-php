<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Router\Answer;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * An answer never carries `tenant_id` (router AC-9, AC-10). The cases both
 * runtimes answer are in fixtures/parity.json (`answers`); what stays here is
 * PHP-only — the routes `Crud::mount` answers, and a `JsonSerializable`.
 */
final class AnswerTest extends TestCase
{
    private const ROWS = [
        ['id' => 'r1', 'tenant_id' => 'acme', 'code' => 'one'],
        ['id' => 'r2', 'tenant_id' => 'acme', 'code' => 'two'],
    ];

    private static function mounted(array $options = []): App
    {
        $e = new Entity('items', 't_items', 'id', ['id' => ['type' => 'text', 'pk' => true], 'code' => ['type' => 'text']], ['list', 'get', 'create', 'update', 'delete']);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['items' => self::ROWS]]), ['items' => $e]);
        $app = new App(['name' => 'items', ...$options]);
        Crud::mount($app, $db, $e, ['path' => '/items']);

        return $app;
    }

    // router AC-9 — An answer carries no tenant_id at any depth
    #[Group('spec:router:AC-9')]
    #[Group('spec:router')]
    public function testTheMountedRoutesAnswerWithoutTheTenant(): void
    {
        $app = self::mounted();
        $list = FakeContext::call($app, 'GET', '/items');
        $this->assertSame([['id' => 'r1', 'code' => 'one'], ['id' => 'r2', 'code' => 'two']], $list['data']['items']);
        $this->assertSame(['id' => 'r1', 'code' => 'one'], FakeContext::call($app, 'GET', '/items/r1')['data']);
        $created = FakeContext::call($app, 'POST', '/items', ['body' => ['id' => 'r3', 'code' => 'three']]);
        $this->assertSame([201, ['id' => 'r3', 'code' => 'three']], [$created['status'], $created['data']]);
        $read = FakeContext::call($app, 'GET', '/items/r1')['data'];
        $this->assertSame($read, FakeContext::call($app, 'PUT', '/items/r1', ['body' => $read])['data']);
    }

    // router AC-10 — An App that must answer tenant_id says so
    #[Group('spec:router:AC-10')]
    #[Group('spec:router')]
    public function testAnAppCreatedToAnswerTheTenantAnswersTheRecordAsStored(): void
    {
        $this->assertSame(self::ROWS[0], FakeContext::call(self::mounted(['answerTenantId' => true]), 'GET', '/items/r1')['data']);
    }

    // router AC-9 — An answer carries no tenant_id at any depth
    #[Group('spec:router:AC-9')]
    #[Group('spec:router')]
    public function testAJsonSerializableIsAnsweredAsWhatItSerialisesTo(): void
    {
        $line = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['id' => 'l1', 'tenant_id' => 'acme'];
            }
        };
        $untouched = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $out = Answer::withoutTenantId(['lines' => [$line], 'at' => $untouched]);
        $this->assertSame('{"lines":[{"id":"l1"}],"at":' . json_encode($untouched) . '}', json_encode($out));
    }
}
