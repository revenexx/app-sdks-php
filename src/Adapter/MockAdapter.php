<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Adapter;

use Revenexx\AppSdk\Adapter;
use Revenexx\AppSdk\EmitsEvents;
use Revenexx\AppSdk\EmitTarget;
use Revenexx\AppSdk\Entity;
use Revenexx\AppSdk\Query;
use Revenexx\AppSdk\RecordsEvents;
use Revenexx\AppSdk\UpdatesConditionally;

/**
 * In-memory adapter. Zero infrastructure: develop + unit-test App logic against
 * fixtures. Does NOT enforce RLS, constraints, triggers or PostgREST semantics —
 * run the `remote` adapter against a dev tenant for that fidelity before deploy.
 */
final class MockAdapter implements Adapter, EmitsEvents, RecordsEvents, UpdatesConditionally
{
    /** @var array<string, array<array-key, array<string, mixed>>> */
    private array $store = [];

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @param array<string, array<int, array<string, mixed>>> $seed */
    public function __construct(private readonly array $seed = [])
    {
    }

    public function kind(): string
    {
        return 'mock';
    }

    /** @return array<array-key, array<string, mixed>> */
    private function &table(Entity $entity): array
    {
        if (! isset($this->store[$entity->name])) {
            $rows = [];
            foreach ($this->seed[$entity->name] ?? [] as $r) {
                $rows[$r[$entity->pk]] = $r;
            }
            $this->store[$entity->name] = $rows;
        }

        return $this->store[$entity->name];
    }

    public function list(Entity $entity, array $query = []): array
    {
        $rows = array_values(array_filter(
            $this->table($entity),
            static fn (array $r): bool => Query::matches($r, $query['where'] ?? []),
        ));

        if (! empty($query['order'])) {
            [$col, $dir] = array_pad(explode('.', (string) $query['order']), 2, 'asc');
            usort($rows, static fn (array $a, array $b): int => ($a[$col] ?? null) <=> ($b[$col] ?? null));
            if ($dir === 'desc') {
                $rows = array_reverse($rows);
            }
        }
        if (! empty($query['offset'])) {
            $rows = \array_slice($rows, (int) $query['offset']);
        }
        if (isset($query['limit'])) {
            $rows = \array_slice($rows, 0, (int) $query['limit']);
        }
        if (! empty($query['select']) && \is_array($query['select'])) {
            $cols = $query['select'];
            $rows = array_map(
                static fn (array $r): array => array_intersect_key($r, array_flip($cols)),
                $rows,
            );
        }

        return $rows;
    }

    public function get(Entity $entity, int|string $id): ?array
    {
        return $this->table($entity)[$id] ?? null;
    }

    public function create(Entity $entity, array $data): array
    {
        $row = $this->applyDefaults($entity, $data);
        if (! isset($row[$entity->pk])) {
            $row[$entity->pk] = self::uuid();
        }
        $this->store[$entity->name][$row[$entity->pk]] = $row;

        return $row;
    }

    public function update(Entity $entity, int|string $id, array $patch): array
    {
        $cur = $this->table($entity)[$id] ?? null;
        if ($cur === null) {
            throw new \RuntimeException("mock: {$entity->name}/{$id} not found");
        }

        return $this->write($entity, $id, $cur, $patch);
    }

    public function updateIf(Entity $entity, int|string $id, array $where, array $patch): ?array
    {
        $cur = $this->table($entity)[$id] ?? null;
        if ($cur === null || ! Query::matches($cur, $where)) {
            return null;
        }

        return $this->write($entity, $id, $cur, $patch);
    }

    /**
     * @param array<string, mixed> $cur
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function write(Entity $entity, int|string $id, array $cur, array $patch): array
    {
        $row = [...$cur, ...$patch];
        if (isset($entity->columns['updated_at'])) {
            $row['updated_at'] = gmdate('c');
        }
        $this->store[$entity->name][$id] = $row;

        return $row;
    }

    public function remove(Entity $entity, int|string $id): void
    {
        unset($this->store[$entity->name][$id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyDefaults(Entity $entity, array $data): array
    {
        $row = $data;
        foreach ($entity->columns as $col => $def) {
            if (isset($row[$col]) || ! \is_array($def)) {
                continue;
            }
            $d = $def['default'] ?? null;
            if ($d === null) {
                continue;
            }
            if (preg_match('/gen_random_uuid/i', (string) $d)) {
                $row[$col] = self::uuid();
            } elseif (preg_match('/now\(\)|current_timestamp/i', (string) $d)) {
                $row[$col] = gmdate('c');
            } elseif (preg_match("/^'(.*)'$/", trim((string) $d), $m)) {
                $row[$col] = $m[1];
            }
        }

        return $row;
    }

    /**
     * Record instead of publish, so an App can assert what it fired without any
     * infrastructure. The signature and the answer are the remote adapter's,
     * deliberately: a double that drifts from the real surface is how a test
     * passes while production breaks.
     *
     * @param array<string, mixed> $data
     * @param array{topicId?: ?string, metadata?: array<string, mixed>, id?: ?string} $options
     * @return array{id: string, topic: string}
     */
    public function emit(EmitTarget $target, array $data = [], array $options = []): array
    {
        $result = [
            'id' => $options['id'] ?? 'evt_' . str_replace('-', '', self::uuid()),
            'topic' => $target->descriptor->topic,
        ];

        // Idempotency the way the outbox gives it: a second emit under an id
        // already used is a no-op and the FIRST payload stands. A mock that
        // recorded both would let a test pass that production silently drops.
        foreach ($this->events as $recorded) {
            if (($recorded['result']['id'] ?? null) === $result['id']) {
                return $result;
            }
        }

        $this->events[] = [
            'event' => $target->name,
            'topic' => $result['topic'],
            'data' => $data,
            'options' => $options,
            'result' => $result,
        ];

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function emitted(): array
    {
        // PHP arrays are copied on assignment, so callers cannot rewrite what
        // the next assertion reads.
        return $this->events;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
