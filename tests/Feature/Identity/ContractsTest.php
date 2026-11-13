<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\PersonAccountLookup;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Tests\Support\Identity\IdentityTestCase;

/** Contrats publiés par Identity aux autres modules : personne d'un compte, existence d'un compte, 2FA récente. */
final class ContractsTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testPersonneLieeAUnCompteDansLeBonClientSeulement(): void
    {
        $ids = $this->createUser('t1', 'Eve', '+237691000020');
        $link = $this->kernel->container()->get(UserPersonLink::class);

        self::assertSame($ids['person_id'], $link->personIdForUser('t1', $ids['user_id']));
        self::assertNull($link->personIdForUser('t2', $ids['user_id']), 'Un compte d\'un autre client est introuvable.');
        self::assertNull($link->personIdForUser('t1', 'inconnu'));
    }

    public function testExistenceDUnCompteParPersonne(): void
    {
        $ids = $this->createUser('t1', 'Eve', '+237691000020');
        $lookup = $this->kernel->container()->get(PersonAccountLookup::class);

        self::assertTrue($lookup->hasAccount('t1', $ids['person_id']));
        self::assertFalse($lookup->hasAccount('t2', $ids['person_id']));
        self::assertFalse($lookup->hasAccount('t1', 'sans-compte'));
    }

    public function testDeuxiemeFacteurRecentSeulementSiValideEtNonExpireNiRevoque(): void
    {
        $ids = $this->createUser('t1', 'Eve', '+237691000020');
        $second = $this->kernel->container()->get(RecentSecondFactor::class);
        $moment = $this->kernel->container()->get(Moment::class);

        self::assertFalse($second->hasRecent('t1', $ids['user_id'], 600), 'Aucune session : pas de preuve.');

        $this->insertSession($ids['user_id'], verifiedAt: $moment->in(-120));
        self::assertTrue($second->hasRecent('t1', $ids['user_id'], 600));
        self::assertFalse($second->hasRecent('t1', $ids['user_id'], 60), 'Trop ancienne pour cette fenêtre.');
        self::assertFalse($second->hasRecent('t2', $ids['user_id'], 600), 'Autre client.');
        self::assertFalse($second->hasRecent('t1', $ids['user_id'], -1));
    }

    public function testUneSessionRevoqueeOuExpireeNeProuvePlusRien(): void
    {
        $ids = $this->createUser('t1', 'Eve', '+237691000020');
        $second = $this->kernel->container()->get(RecentSecondFactor::class);
        $moment = $this->kernel->container()->get(Moment::class);

        $this->insertSession($ids['user_id'], verifiedAt: $moment->in(-10), revokedAt: $moment->in(-5));
        $this->insertSession($ids['user_id'], verifiedAt: $moment->in(-10), expiresAt: $moment->in(-1));
        $this->insertSession($ids['user_id'], verifiedAt: null);

        self::assertFalse($second->hasRecent('t1', $ids['user_id'], 600));
    }

    private function insertSession(string $userId, ?string $verifiedAt, ?string $revokedAt = null, ?string $expiresAt = null): void
    {
        $moment = $this->kernel->container()->get(Moment::class);
        $this->pdo()->prepare(
            'INSERT INTO access_tokens (id, tenant_id, user_id, device_id, token_hash, remember, expires_at, needs_two_factor, two_factor_verified_at, two_factor_attempts, revoked_at, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?, 0, ?, ?)',
        )->execute([(string) new Ulid(), 't1', $userId, 'd1', hash('sha256', (string) new Ulid()), $expiresAt ?? $moment->in(3600), $verifiedAt, $revokedAt, $moment->iso()]);
    }
}
