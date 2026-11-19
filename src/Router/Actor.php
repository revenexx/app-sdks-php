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
 *
 * A sales rep (ADR-0132 §9, PE-918) arrives as `x-revenexx-caller: rep` with
 * `x-revenexx-rep` (the rep code) and never with the contact headers. Plane
 * `rep`, trust always `asserted`. Both halves are needed; when a rep is named
 * the contact headers are ignored (the narrower reading). The record-history
 * envelope `x-revenexx-audit` is not read here — the adapters forward it to the
 * data plane unchanged.
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
        'caller' => 'x-revenexx-caller',
        'rep' => 'x-revenexx-rep',
    ];

    /**
     * @param list<string> $permissions
     * @param 'authenticated'|'asserted'|'none' $trust
     * @param 'rep'|'contact'|'operator'|'system' $plane
     */
    private function __construct(
        public readonly ?string $user,
        public readonly ?string $email,
        public readonly ?string $contact,
        public readonly ?string $organization,
        public readonly array $permissions,
        public readonly string $trust,
        public readonly string $plane,
        public readonly ?string $rep = null,
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
        // A rep needs the caller kind AND the code; when one is named the
        // contact headers are ignored.
        $callerKind = strtolower(trim($read(self::HEADERS['caller'])));
        $rep = $callerKind === 'rep' ? $nullIfEmpty($read(self::HEADERS['rep'])) : null;
        $contact = $rep === null ? $nullIfEmpty($read(self::HEADERS['contact'])) : null;
        $organization = $rep === null ? $nullIfEmpty($read(self::HEADERS['organization'])) : null;
        $permissions = array_values(array_filter(
            array_map('trim', explode(',', $read(self::HEADERS['permissions']))),
            static fn (string $p): bool => $p !== '',
        ));

        // Nobody acting is `none`, whatever a stray header says. A trust value
        // the SDK does not know — or `authenticated` beside no user — degrades
        // to `asserted`, never upwards.
        $raw = strtolower(trim($read(self::HEADERS['trust'])));
        // A rep is asserted by the rep portal's server, never authenticated.
        $trust = $user === null && $contact === null && $rep === null
            ? 'none'
            : ($rep === null && $user !== null && $raw === 'authenticated' ? 'authenticated' : 'asserted');
        $plane = $rep !== null
            ? 'rep'
            : ($contact !== null ? 'contact' : ($user !== null ? 'operator' : 'system'));

        return new self(
            $user,
            $nullIfEmpty($read(self::HEADERS['email'])),
            $contact,
            $organization,
            $permissions,
            $trust,
            $plane,
            $rep,
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
