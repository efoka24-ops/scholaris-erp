<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Tests\Support\Identity\IdentityTestCase;

/**
 * D3 (recette) : ouvrir le compte d'une personne déjà enregistrée (garant inscrit par le secrétariat) rattache le compte
 * à sa fiche au lieu d'en créer une seconde (fiche unique par client, FR-006).
 */
final class ExistingPersonAccountTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta');
    }

    private function insertPerson(string $tenant, string $first, string $last, ?string $phone, ?string $email = null): string
    {
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenant, $first, $last, $phone, $email, 'fr', '2026-10-01T08:00:00+01:00']);

        return $id;
    }

    private function provisioner(): AccountProvisioner
    {
        return $this->kernel->container()->get(AccountProvisioner::class);
    }

    private function peopleWithPhone(string $phone): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM people WHERE phone = ?');
        $statement->execute([$phone]);

        return (int) $statement->fetchColumn();
    }

    public function testLeCompteEstRattacheALaFicheExistanteDeMemeContactEtMemeNom(): void
    {
        $existing = $this->insertPerson('t1', 'Paul', 'Foka', '+237677555111');

        $ids = $this->provisioner()->provision('t1', ' paul ', 'FOKA', '677 55 51 11', null);

        self::assertSame($existing, $ids['person_id']);
        self::assertTrue($ids['person_linked'] ?? false);
        self::assertSame(1, $this->peopleWithPhone('+237677555111'));
    }

    public function testParLApiAvecLaFicheDesigneeExplicitement(): void
    {
        $this->createUser('t1', 'Diane', '+237691000035', [['directeur', 'establishment', 'e1']]);
        $existing = $this->insertPerson('t1', 'Marie', 'Ngo', null, 'marie.ngo@example.test');
        $token = $this->tokenWithTwoFactor('+237691000035');

        $created = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'Marie', 'last_name' => 'Ngo Bassa', 'phone' => '+237677555222', 'person_id' => $existing]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame($existing, $this->json($created)['data']['person_id']);
        self::assertTrue($this->json($created)['data']['person_linked']);

        $again = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'Marie', 'last_name' => 'Ngo', 'phone' => '+237677555333', 'person_id' => $existing]);
        self::assertSame(409, $again->getStatusCode(), 'La fiche a déjà un compte.');
        $unknown = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '+237677555444', 'person_id' => (string) new Ulid()]);
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function testFicheDUnAutreClientOuNomDifferentOuAmbiguiteCreeUneNouvelleFiche(): void
    {
        $this->insertPerson('t2', 'Paul', 'Foka', '+237677555111');
        $this->insertPerson('t1', 'Pauline', 'Foka', '+237677555111');
        $ids = $this->provisioner()->provision('t1', 'Paul', 'Foka', '+237677555111', null);
        self::assertFalse($ids['person_linked'] ?? true);

        $this->insertPerson('t1', 'Jean', 'Eto', '+237677555999');
        $this->insertPerson('t1', 'Jean', 'Eto', '+237677555999');
        $ambiguous = $this->provisioner()->provision('t1', 'Jean', 'Eto', '+237677555999', null);
        self::assertFalse($ambiguous['person_linked'] ?? true, 'Deux fiches candidates : aucun choix arbitraire.');

        $other = (string) $this->pdo()->query("SELECT id FROM people WHERE tenant_id = 't2'")->fetchColumn();
        self::assertNotSame($other, $ids['person_id']);
    }

    private function tokenWithTwoFactor(string $phone): string
    {
        $token = (string) $this->json($this->login($phone))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        return $token;
    }
}
