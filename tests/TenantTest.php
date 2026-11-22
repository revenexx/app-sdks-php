<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Tenant;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * Rows the data client returns carry no `tenant_id` (data-client AC-21 to
 * AC-24). The cases both runtimes answer are in fixtures/parity.json
 * (`client_reads`); what stays here is PHP-only.
 */
final class TenantTest extends TestCase
{
    private const ROWS = [
        ['id' => 'r1', 'tenant_id' => 'acme', 'code' => 'one'],
        ['id' => 'r2', 'tenant_id' => 'acme', 'code' => 'two'],
    ];

    private static function mounted(bool $keep = false): App
    {
        $e = new Entity('items', 't_items', 'id', ['id' => ['type' => 'text', 'pk' => true], 'code' => ['type' => 'text']], ['list', 'get', 'create', 'update', 'delete']);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['items' => self::ROWS]]), ['items' => $e], keepTenantId: $keep);
        $app = new App(['name' => 'items']);
        Crud::mount($app, $db, $e, ['path' => '/items']);

        return $app;
    }

    // data-client AC-21 — Rows the client returns carry no tenant_id
    #[Group('spec:data-client:AC-21')]
    #[Group('spec:data-client')]
    public function testTheMountedRoutesAnswerWithoutTheTenant(): void
    {
        $app = self::mounted();
        $this->assertSame([['id' => 'r1', 'code' => 'one'], ['id' => 'r2', 'code' => 'two']], FakeContext::call($app, 'GET', '/items')['data']['items']);
        $this->assertSame(['id' => 'r1', 'code' => 'one'], FakeContext::call($app, 'GET', '/items/r1')['data']);
        $created = FakeContext::call($app, 'POST', '/items', ['body' => ['id' => 'r3', 'code' => 'three']]);
        $this->assertSame([201, ['id' => 'r3', 'code' => 'three']], [$created['status'], $created['data']]);
    }

    // data-client AC-21 — Rows the client returns carry no tenant_id
    #[Group('spec:data-client:AC-21')]
    #[Group('spec:data-client')]
    #[Group('spec:crud-routes:AC-12')]
    #[Group('spec:crud-routes')]
    public function testAPutNamingAnotherTenantIsStillRefused(): void
    {
        $app = self::mounted();
        $other = FakeContext::call($app, 'PUT', '/items/r1', ['body' => ['code' => 'uno', 'tenant_id' => 'globex']]);
        $this->assertSame([400, 'store_owned_field'], [$other['status'], $other['data']['code']]);
        $same = FakeContext::call($app, 'PUT', '/items/r1', ['body' => ['code' => 'uno', 'tenant_id' => 'acme']]);
        $this->assertSame([200, ['id' => 'r1', 'code' => 'uno']], [$same['status'], $same['data']]);
    }

    // data-client AC-23 — An App that must read tenant_id says so
    #[Group('spec:data-client:AC-23')]
    #[Group('spec:data-client')]
    public function testAClientCreatedToKeepTheTenantAnswersTheRecordAsStored(): void
    {
        $this->assertSame(self::ROWS[0], FakeContext::call(self::mounted(true), 'GET', '/items/r1')['data']);
    }

    // data-client AC-24 — Rows read past the client are stripped the same way, shallowly
    #[Group('spec:data-client:AC-24')]
    #[Group('spec:data-client')]
    public function testWithoutTenantIdDropsTheTopLevelKeyOnly(): void
    {
        $row = ['id' => 'r1', 'tenant_id' => 'acme', 'payload' => ['tenant_id' => 'psp']];
        $this->assertSame(['id' => 'r1', 'payload' => ['tenant_id' => 'psp']], Tenant::withoutTenantId($row));
        $this->assertSame([['id' => 'r1', 'payload' => ['tenant_id' => 'psp']], ['id' => 'r2']], Tenant::withoutTenantId([$row, ['id' => 'r2']]));
        $this->assertNull(Tenant::withoutTenantId(null));
    }
}
