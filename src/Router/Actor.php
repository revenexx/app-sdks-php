<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Router;

/**
 * Who is acting behind a call, and how far each part of that was established —
 * the counterpart of `c.actor` in the JavaScript SDK.
 *
 * `trust` is the load-bearing field: a storefront server naming a contact and a
 * person proving themselves arrive in the same headers. Today the platform
 * verifies no person itself, so `authenticated()` answers false for every
 * caller — the honest answer.
 */
final class Actor
{
    public const HEADERS = [
        'user' => 'x-revenexx-actor',
        'email' => 'x-revenexx-actor-email',
        'trust' => 'x-revenexx-actor-trust',
        'contact' => 'x-revenexx-contact',
        'organization' => 'x-revenexx-organization',
        'permissions' => 'x-revenexx-permissions',
    ];

    /**
     * @param list<string> $permissions
     * @param 'authenticated'|'asserted'|'none' $trust
     * @param 'contact'|'operator'|'system' $plane
     */
    private function __construct(
        public readonly ?string $user,
        public readonly ?string $email,
        public readonly ?string $contact,
        public readonly ?string $organization,
        public readonly array $permissions,
        public readonly string $trust,
        public readonly string $plane,
    ) {
    }

    /** @param callable(string): (string|null) $header reads one header by lower-case name */
    public static function fromHeaders(callable $header): self
    {
        $read = static function (string $name) use ($header): string {
            $value = $header($name);

            return \is_string($value) ? $value : '';
        };
        $nullIfEmpty = static fn (string $v): ?string => trim($v) === '' ? null : trim($v);

        $user = $nullIfEmpty($read(self::HEADERS['user']));
        $contact = $nullIfEmpty($read(self::HEADERS['contact']));
        $permissions = array_values(array_filter(
            array_map('trim', explode(',', $read(self::HEADERS['permissions']))),
            static fn (string $p): bool => $p !== '',
        ));

        // Nobody acting is `none`, whatever a stray header says. A trust value
        // the SDK does not know — or `authenticated` beside no user — degrades
        // to `asserted`, never upwards.
        $raw = strtolower(trim($read(self::HEADERS['trust'])));
        $trust = $user === null && $contact === null
            ? 'none'
            : ($user !== null && $raw === 'authenticated' ? 'authenticated' : 'asserted');
        $plane = $contact !== null ? 'contact' : ($user !== null ? 'operator' : 'system');

        return new self(
            $user,
            $nullIfEmpty($read(self::HEADERS['email'])),
            $contact,
            $nullIfEmpty($read(self::HEADERS['organization'])),
            $permissions,
            $trust,
            $plane,
        );
    }

    public function holds(string $permission): bool
    {
        return $permission !== '' && \in_array($permission, $this->permissions, true);
    }

    public function authenticated(): bool
    {
        return $this->trust === 'authenticated';
    }
}
