<?php

declare(strict_types=1);

namespace Revenexx\AppSdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Revenexx\AppSdk\Router\Actor;

/** Who is acting — the same cases the JavaScript SDK tests. */
final class ActorTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function from(array $headers): Actor
    {
        return Actor::fromHeaders(static fn (string $n): string => $headers[$n] ?? '');
    }

    // acting-person AC-1 — A person the gateway authenticated is read whole
    #[Group('spec:acting-person:AC-1')]
    #[Group('spec:acting-person')]
    public function testReadsAnAuthenticatedPersonWhole(): void
    {
        $a = self::from([
            'x-revenexx-actor' => 'zitadel-sub',
            'x-revenexx-actor-email' => 'bea@acme.test',
            'x-revenexx-actor-trust' => 'authenticated',
            'x-revenexx-contact' => 'c1',
            'x-revenexx-organization' => 'o1',
            'x-revenexx-permissions' => 'orders.create,orders.approve',
        ]);
        $this->assertSame(['zitadel-sub', 'bea@acme.test', 'c1', 'o1', 'contact', true], [$a->user, $a->email, $a->contact, $a->organization, $a->plane, $a->authenticated()]);
        $this->assertTrue($a->holds('orders.approve'));
        $this->assertFalse($a->holds('orders.cancel'));
    }

    // acting-person AC-2 — A contact a server names is asserted, not proven
    #[Group('spec:acting-person:AC-2')]
    #[Group('spec:acting-person')]
    public function testAContactAServerNamesIsAsserted(): void
    {
        foreach ([['x-revenexx-actor-trust' => 'asserted', 'x-revenexx-contact' => 'c1'], ['x-revenexx-contact' => 'c1', 'x-revenexx-organization' => 'o1']] as $headers) {
            $a = self::from($headers);
            $this->assertSame([null, 'c1', 'contact', 'asserted', false], [$a->user, $a->contact, $a->plane, $a->trust, $a->authenticated()]);
        }
    }

    // acting-person AC-3 — An authenticated human without a contact is the operator plane
    #[Group('spec:acting-person:AC-3')]
    #[Group('spec:acting-person')]
    public function testAnAuthenticatedHumanWithoutContactIsTheOperatorPlane(): void
    {
        $a = self::from(['x-revenexx-actor' => 'operator-sub', 'x-revenexx-actor-trust' => 'authenticated']);
        $this->assertSame(['operator', null, [], false], [$a->plane, $a->contact, $a->permissions, $a->holds('orders.approve')]);
    }

    // acting-person AC-4 — Nobody acting is `system`, whatever a stray header says
    #[Group('spec:acting-person:AC-4')]
    #[Group('spec:acting-person')]
    public function testNobodyActingIsSystem(): void
    {
        foreach ([[], ['x-revenexx-actor-trust' => 'authenticated']] as $headers) {
            $a = self::from($headers);
            $this->assertSame(['system', 'none', false], [$a->plane, $a->trust, $a->authenticated()]);
        }
    }

    // acting-person AC-5 — An unknown or orphaned trust degrades downwards
    #[Group('spec:acting-person:AC-5')]
    #[Group('spec:acting-person')]
    public function testAnUnknownOrOrphanedTrustDegradesDownwards(): void
    {
        foreach (['delegated', 'authenticated'] as $trust) {
            $a = self::from(['x-revenexx-contact' => 'c1', 'x-revenexx-actor-trust' => $trust]);
            $this->assertSame(['asserted', false], [$a->trust, $a->authenticated()], $trust);
        }
    }

    // acting-person AC-6 — Blank and missing headers read as absent
    #[Group('spec:acting-person:AC-6')]
    #[Group('spec:acting-person')]
    public function testBlankAndMissingHeadersReadAsAbsent(): void
    {
        $a = self::from(['x-revenexx-actor' => '  ', 'x-revenexx-contact' => 'c1', 'x-revenexx-permissions' => 'orders.create, ,orders.approve,']);
        $this->assertSame([null, ['orders.create', 'orders.approve'], false], [$a->user, $a->permissions, $a->holds('')]);
        $bag = ['x-revenexx-contact' => 'c1'];
        $b = Actor::fromHeaders(static fn (string $n): ?string => $bag[$n] ?? null);
        $this->assertSame(['c1', null, [], 'asserted'], [$b->contact, $b->user, $b->permissions, $b->trust]);
    }
}
