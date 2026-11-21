<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Adapter\MockAdapter;
use Revenexx\AppSdk\Adapter\RemoteAdapter;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\HttpError;
use Revenexx\AppSdk\Router\App;
use Revenexx\AppSdk\Router\Crud;
use Revenexx\AppSdk\Tests\Support\FakeContext;

/**
 * A version writes only the columns it declares. During a rollout the new
 * schema reaches the database before the new code is served, so the database
 * alone would let the old code store a key it never knew into the new column.
 * Same promises as the JavaScript SDK (test/unknown-field.test.ts).
 */
final class UnknownFieldTest extends TestCase
{
    private const COLUMNS = [
        'id' => ['type' => 'uuid', 'pk' => true, 'default' => 'gen_random_uuid()'],
        'name' => ['type' => 'text', 'notNull' => true],
        'settings' => ['type' => 'jsonb'],
        'deleted_at' => ['type' => 'timestamptz'],
        'created_at' => ['type' => 'timestamptz', 'default' => 'now()'],
        'updated_at' => ['type' => 'timestamptz', 'default' => 'now()'],
    ];

    private const SEED = ['accounts' => [['id' => 'a1', 'name' => 'Acme', 'settings' => null]]];

    private static function accounts(array $columns = self::COLUMNS): Entity
    {
        return new Entity('accounts', 'revenexx__punchout__accounts', 'id', $columns, ['list', 'get', 'create', 'update', 'delete']);
    }

    /** @param list<?string> $sent */
    private static function remote(array &$sent): RemoteAdapter
    {
        return new RemoteAdapter('http://pgrst:3000', transport: static function (string $method, string $url, array $headers, ?string $body) use (&$sent): array {
            $sent[] = $body;

            return ['status' => 200, 'body' => (string) json_encode([['id' => 'a1', 'name' => 'Acme', ...json_decode($body ?? '{}', true)]])];
        });
    }

    private static function refusal(callable $call): HttpError
    {
        try {
            $call();
        } catch (HttpError $e) {
            return $e;
        }
        self::fail('expected a refusal');
    }

    // data-client AC-13 — A write naming a field that is not a column is refused before any request
    #[Group('spec:data-client:AC-13')]
    #[Group('spec:data-client')]
    public function testCreateUpdateAndUpdateIfRefuseAnUndeclaredKeyOnEveryAdapter(): void
    {
        $sent = [];
        $e = self::accounts();
        $allowed = array_keys(self::COLUMNS);
        sort($allowed);

        foreach ([AdapterRegistry::create('mock', ['seed' => self::SEED]), self::remote($sent)] as $adapter) {
            $db = new Client($adapter, ['accounts' => $e]);
            foreach ([
                static fn () => $db->create($e, ['name' => 'New', 'frame_ancestors' => '*']),
                static fn () => $db->update($e, 'a1', ['name' => 'Neu', 'frame_ancestors' => '*']),
                static fn () => $db->updateIf($e, 'a1', ['name' => 'Acme'], ['frame_ancestors' => '*']),
            ] as $write) {
                $err = self::refusal($write);
                $this->assertSame([400, 'unknown_field'], [$err->status, $err->errorCode]);
                $this->assertStringContainsString("'frame_ancestors'", $err->getMessage());
                $this->assertSame(['unknown' => ['frame_ancestors'], 'allowed' => $allowed], $err->details);
            }
            if ($adapter instanceof MockAdapter) {
                $this->assertSame(self::SEED['accounts'][0], $db->get($e, 'a1'));
            }
        }
        $this->assertSame([], $sent);
    }

    // data-client AC-13 — A write naming a field that is not a column is refused before any request
    #[Group('spec:data-client:AC-13')]
    #[Group('spec:data-client')]
    public function testEveryUnknownKeyIsNamedSortedAndANullValueDoesNotHideOne(): void
    {
        $e = self::accounts();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => self::SEED]), ['accounts' => $e]);
        $err = self::refusal(static fn () => $db->create($e, ['name' => 'x', 'market' => 'de', '_actor' => null, 'at' => '2026-01-01T00:00:00Z']));

        $this->assertSame(['_actor', 'at', 'market'], $err->details['unknown']);
        $this->assertSame(
            "unknown fields '_actor', 'at', 'market' — not a column of 'accounts'. A field that cannot be written is refused rather than dropped; see details.allowed.",
            $err->getMessage(),
        );
    }

    // data-client AC-13 — A write naming a field that is not a column is refused before any request
    #[Group('spec:data-client:AC-13')]
    #[Group('spec:data-client')]
    public function testTheAdaptersRefuseTheSameKeysWhenUsedWithoutTheClient(): void
    {
        $sent = [];
        $e = self::accounts();
        foreach ([AdapterRegistry::create('mock', ['seed' => self::SEED]), self::remote($sent)] as $adapter) {
            $this->assertSame('unknown_field', self::refusal(static fn () => $adapter->create($e, ['name' => 'x', 'frame_ancestors' => '*']))->errorCode);
            $this->assertSame('unknown_field', self::refusal(static fn () => $adapter->update($e, 'a1', ['frame_ancestors' => '*']))->errorCode);
            $this->assertSame('unknown_field', self::refusal(static fn () => $adapter->updateIf($e, 'a1', ['name' => 'Acme'], ['frame_ancestors' => '*']))->errorCode);
        }
        $this->assertSame([], $sent);
    }

    // data-client AC-14 — A declared column, a document inside a jsonb column and tenant_id are written as before
    #[Group('spec:data-client:AC-14')]
    #[Group('spec:data-client')]
    public function testAJsonbColumnTakesAnyDocumentAndSystemColumnsAreWritten(): void
    {
        $sent = [];
        $e = self::accounts();
        $legacy = new Entity('legacy', 't_legacy', 'id', [], ['create']);
        $db = new Client(self::remote($sent), ['accounts' => $e, 'legacy' => $legacy]);
        $settings = ['frame_ancestors' => '*', 'nested' => ['anything' => [1, 'two', null]]];

        $db->create($e, ['name' => 'x', 'settings' => $settings, 'tenant_id' => 'acme', 'created_at' => '2026-01-01T00:00:00Z']);
        $db->update($e, 'a1', ['deleted_at' => '2026-01-02T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z']);
        // An entity whose descriptor lists no columns is not checked.
        $db->create($legacy, ['anything' => 1]);

        // An update leaves updated_at to Baseline, which stamps it (data-client AC-19).
        $this->assertSame([
            ['name' => 'x', 'settings' => $settings, 'tenant_id' => 'acme', 'created_at' => '2026-01-01T00:00:00Z'],
            ['deleted_at' => '2026-01-02T00:00:00Z'],
            ['anything' => 1],
        ], array_map(static fn (?string $b): mixed => json_decode((string) $b, true), $sent));
    }

    // crud-routes AC-10 — A create or update naming a field the resource does not have is a 400
    #[Group('spec:crud-routes:AC-10')]
    #[Group('spec:crud-routes')]
    public function testPostAndPutAnswer400UnknownFieldAndWriteNothing(): void
    {
        $e = self::accounts();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => self::SEED]), ['accounts' => $e]);
        $app = new App(['name' => 'punchout']);
        Crud::mount($app, $db, $e, ['path' => '/accounts']);

        $created = FakeContext::call($app, 'POST', '/accounts', ['body' => ['name' => 'New', 'frame_ancestors' => '*']]);
        $this->assertSame([400, 'unknown_field', ['frame_ancestors']], [$created['status'], $created['data']['code'], $created['data']['details']['unknown']]);

        $updated = FakeContext::call($app, 'PUT', '/accounts/a1', ['body' => ['frame_ancestors' => '*']]);
        $this->assertSame([400, 'unknown_field'], [$updated['status'], $updated['data']['code']]);

        $this->assertSame(self::SEED['accounts'], $db->list($e));
        $this->assertSame(200, FakeContext::call($app, 'PUT', '/accounts/a1', ['body' => ['name' => 'Neu', 'settings' => ['x' => 1]]])['status']);
    }

    // data-client AC-15 — A column the entity's trash or external_id flag adds is written like a declared one
    #[Group('spec:data-client:AC-15')]
    #[Group('spec:data-client')]
    public function testTheColumnsAFlagAddsAreWrittenAndRefusedWithoutTheFlag(): void
    {
        $columns = ['id' => ['type' => 'text', 'pk' => true], 'title' => ['type' => 'text']];
        $ops = ['list', 'get', 'create', 'update'];
        $notes = new Entity('notes', 't_notes', 'id', $columns, $ops, trash: true, externalId: true);
        $plain = new Entity('plain', 't_plain', 'id', $columns, $ops);

        $sent = [];
        $remote = new RemoteAdapter('http://pgrst:3000', transport: static function (string $m, string $u, array $h, ?string $body) use (&$sent): array {
            $sent[] = json_decode((string) $body, true);

            return ['status' => 200, 'body' => (string) json_encode([['id' => 'n1']])];
        });
        $db = new Client($remote, ['notes' => $notes, 'plain' => $plain]);
        $db->create($notes, ['id' => 'n2', 'external_id' => 'ERP-2']);
        $db->update($notes, 'n1', ['deleted_at' => '2026-01-01T00:00:00Z', 'trash_batch' => 'b1', 'purge_blocked_reason' => null]);
        $this->assertSame([
            ['id' => 'n2', 'external_id' => 'ERP-2'],
            ['deleted_at' => '2026-01-01T00:00:00Z', 'trash_batch' => 'b1', 'purge_blocked_reason' => null],
        ], $sent);

        try {
            $db->create($plain, ['id' => 'p1', 'external_id' => 'ERP-1', 'deleted_at' => null]);
            $this->fail('expected unknown_field');
        } catch (HttpError $e) {
            $this->assertSame('unknown_field', $e->errorCode);
            $this->assertSame(['unknown' => ['deleted_at', 'external_id'], 'allowed' => ['id', 'title']], $e->details);
        }

        try {
            $db->create($notes, ['id' => 'n3', 'frame_ancestors' => '*']);
            $this->fail('expected unknown_field');
        } catch (HttpError $e) {
            $this->assertSame(
                ['deleted_at', 'deleted_by', 'deleted_by_id', 'external_id', 'id', 'purge_blocked_reason', 'title', 'trash_batch'],
                $e->details['allowed'],
            );
        }
        $this->assertCount(2, $sent);

        // The mock reads by them too, as the data plane does.
        $mock = new Client(AdapterRegistry::create('mock', ['seed' => ['notes' => [['id' => 'n1', 'title' => 'One']]]]), ['notes' => $notes]);
        $mock->update($notes, 'n1', ['external_id' => 'ERP-1']);
        $this->assertCount(1, $mock->list($notes, ['where' => ['external_id' => 'ERP-1', 'deleted_at' => null]]));
    }

    private const ONE_RECORD = 'a write takes one record, as a JSON object — not a list';

    // data-client AC-16 — A write is one record, never a list
    // The cases both runtimes answer — client, remote, mounted route and a
    // hand-written handler — are in fixtures/parity.json (`writes`). What stays
    // here is the adapter used without the client.
    #[Group('spec:data-client:AC-16')]
    #[Group('spec:data-client')]
    public function testTheAdaptersRefuseAListWhenUsedWithoutTheClient(): void
    {
        $list = [['name' => 'New', 'evil' => 1]];
        $sent = [];
        $e = self::accounts();
        foreach ([AdapterRegistry::create('mock', ['seed' => self::SEED]), self::remote($sent)] as $adapter) {
            foreach ([
                static fn () => $adapter->create($e, $list),
                static fn () => $adapter->update($e, 'a1', $list),
            ] as $write) {
                $err = self::refusal($write);
                $this->assertSame([400, 'bad_request', self::ONE_RECORD, null], [$err->status, $err->errorCode, $err->getMessage(), $err->details]);
            }
        }
        $this->assertSame([], $sent);
    }

    // mock-fidelity AC-2 — A condition on an undeclared column fails like PostgREST
    #[Group('spec:mock-fidelity:AC-2')]
    #[Group('spec:mock-fidelity')]
    public function testTheMockFiltersOnTheColumnsAFlagAddsAndRefusesThemWithoutTheFlag(): void
    {
        $columns = ['id' => ['type' => 'text', 'pk' => true], 'title' => ['type' => 'text']];
        $ops = ['list', 'get', 'create', 'update'];
        $notes = new Entity('notes', 't_notes', 'id', $columns, $ops, trash: true, externalId: true);
        $plain = new Entity('plain', 't_plain', 'id', $columns, $ops);
        $rows = [['id' => 'n1', 'title' => 'One', 'external_id' => 'ERP-1']];
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['notes' => $rows, 'plain' => $rows]]), ['notes' => $notes, 'plain' => $plain]);

        $this->assertCount(1, $db->list($notes, ['where' => ['external_id' => 'ERP-1', 'deleted_at' => null]]));
        $this->assertSame(0, $db->page($notes, ['where' => ['trash_batch' => 'b1']])['total']);
        $this->assertSame('Eins', $db->updateIf($notes, 'n1', ['external_id' => 'ERP-1'], ['title' => 'Eins'])['title'] ?? null);

        foreach ([
            static fn () => $db->list($plain, ['where' => ['external_id' => 'ERP-1']]),
            static fn () => $db->page($plain, ['where' => ['deleted_at' => null]]),
            static fn () => $db->updateIf($plain, 'n1', ['external_id' => 'ERP-1'], ['title' => 'Eins']),
        ] as $read) {
            $err = self::refusal($read);
            $this->assertSame([400, 'bad_request'], [$err->status, $err->errorCode]);
        }
    }
}
