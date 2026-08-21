<?php

declare(strict_types=1);

namespace Revenexx\AppSdk;

/**
 * Fire one of the App's own declared events. The PHP mirror of the TS runtime's
 * `db.events` — same check, same wording, same answer.
 *
 * The name check happens here, before the round trip, because this is where the
 * declared set actually lives. The generated routine refuses an undeclared name
 * too — that is the backstop — but it can only answer with a Postgres error,
 * and a typo in an event name deserves to say so.
 */
final class Events
{
    /** @param array<string, EventDescriptor> $declared */
    public function __construct(
        private readonly Adapter $adapter,
        private readonly string $fn,
        private readonly array $declared,
    ) {
    }

    /** @return array<string, EventDescriptor> */
    public function declared(): array
    {
        return $this->declared;
    }

    /**
     * @param array<string, mixed>                                                $data
     * @param array{topicId?: ?string, metadata?: array<string, mixed>, id?: ?string} $options
     * @return array{id: string, topic: string}
     */
    public function emit(string $name, array $data = [], array $options = []): array
    {
        $descriptor = $this->declared[$name] ?? null;
        if ($descriptor === null) {
            $names = array_keys($this->declared);
            sort($names);

            throw new \InvalidArgumentException(sprintf(
                "events.emit('%s'): not declared by this app — add it to manifest.json events.emits[] with \"trigger\": \"app\". Declared: %s",
                $name,
                $names === [] ? '(none)' : implode(', ', $names),
            ));
        }

        if (! $this->adapter instanceof EmitsEvents) {
            throw new \RuntimeException(
                "events.emit('{$name}') is not supported by the '{$this->adapter->kind()}' adapter",
            );
        }

        return $this->adapter->emit(new EmitTarget($this->fn, $name, $descriptor), $data, $options);
    }

    /**
     * What was emitted, for tests. Only the mock adapter records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function emitted(): array
    {
        if (! $this->adapter instanceof RecordsEvents) {
            throw new \RuntimeException(
                "events.emitted() is only available on the 'mock' adapter — the '{$this->adapter->kind()}' adapter publishes for real",
            );
        }

        return $this->adapter->emitted();
    }
}
