<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\AdapterRegistry;
use Revenexx\AppSdk\Client;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;

/**
 * JS ↔ PHP parity. Both runtimes answer the cases in fixtures/parity.json; the
 * TS half is packages/sdk/test/parity.test.ts. A case belongs in the fixture,
 * not in one runtime's test.
 */
final class ParityTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $ops */
    private static function things(array $ops = ['list', 'get']): Entity
    {
        return new Entity(
            name: 'things',
            table: 't_things',
            pk: 'id',
            columns: ['id' => ['type' => 'text', 'pk' => true], 'status' => ['type' => 'text'], 'n' => ['type' => 'int'], 'name' => ['type' => 'text'], 'tag' => ['type' => 'text']],
            ops: $ops,
        );
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, string}>}> */
    public static function queries(): iterable
    {
        foreach (self::fixture()['queries'] as $case) {
            yield $case['name'] => [$case['query'], $case['params']];
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function matchCases(): iterable
    {
        foreach (self::fixture()['matches'] as $case) {
            yield $case['name'] => [$case['where'], $case['ids']];
        }
    }

    /**
     * @param array<string, mixed>         $query
     * @param list<array{string, string}> $params
     */
    // runtime-parity AC-1 — A query serialises to the same parameters in both runtimes
    #[Group('spec:runtime-parity:AC-1')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('queries')]
    public function testSerialisesToTheFixturesParameters(array $query, array $params): void
    {
        $pairs = array_map(
            static fn (string $pair): array => array_map('rawurldecode', explode('=', $pair, 2)),
            explode('&', Query::toParams($query)),
        );

        self::assertSame($params, $pairs);
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>         $ids
     */
    // runtime-parity AC-2 — A query matches the same rows in both mocks
    #[Group('spec:runtime-parity:AC-2')]
    #[Group('spec:runtime-parity')]
    #[DataProvider('matchCases')]
    public function testTheMockMatchesTheFixturesRows(array $where, array $ids): void
    {
        $e = self::things();
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['things' => self::fixture()['rows']]]), [$e->name => $e]);

        self::assertSame($ids, array_column($db->list($e, ['where' => $where, 'order' => 'id.asc']), 'id'));
    }

    // runtime-parity AC-3 — A refusal reads the same in both runtimes
    #[Group('spec:runtime-parity:AC-3')]
    #[Group('spec:runtime-parity')]
    public function testRefusalsUseTheFixturesWording(): void
    {
        $messages = self::fixture()['messages'];
        $e = self::things(['list', 'get', 'update']);
        $db = new Client(AdapterRegistry::create('mock', ['seed' => ['things' => self::fixture()['rows']]]), [$e->name => $e]);

        foreach ([
            $messages['emptyUpdate'] => static fn () => $db->update($e, '1', []),
            $messages['emptyCondition'] => static fn () => $db->updateIf($e, '1', [], ['n' => 2]),
            $messages['notPermitted'] => static fn () => $db->create($e, []),
        ] as $message => $call) {
            try {
                $call();
                self::fail("expected: {$message}");
            } catch (\RuntimeException|\InvalidArgumentException $ex) {
                self::assertSame($message, $ex->getMessage());
            }
        }
    }
}
