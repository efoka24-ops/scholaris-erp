<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\ApplicantRegistration;
use Tests\Support\Identity\IdentityTestCase;

/** Inscription publique d'un candidat : compte en attente, rôle de libre-service seul, code d'activation, aucune fuite d'existence. */
final class ApplicantRegistrationTest extends IdentityTestCase
{
    private const PHONE = '+237691000077';

    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'IPES Test');
        $this->directory->add('t1', 'e2', 'IPES Autre');
    }

    private function registrar(): ApplicantRegistration
    {
        return $this->kernel->container()->get(ApplicantRegistration::class);
    }

    /** @return list<array<string, mixed>> */
    private function roles(string $phone): array
    {
        $statement = $this->pdo()->prepare('SELECT r.role, r.scope_type, r.establishment_id FROM role_assignments r JOIN users u ON u.person_id = r.person_id AND u.tenant_id = r.tenant_id WHERE u.phone = ?');
        $statement->execute([$phone]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testCreeLeCompteEnAttenteAvecLeSeulRoleApprenantEtEnvoieLeCode(): void
    {
        $answer = $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', '691 00 00 77');

        self::assertSame(['sms', '+237 6•• •• 00 77'], [$answer['channel'], $answer['masked_to']]);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $this->sms->lastCode(self::PHONE));
        self::assertSame([['role' => 'apprenant', 'scope_type' => 'establishment', 'establishment_id' => 'e1']], $this->roles(self::PHONE));

        // Parcours d'activation existant : code, mot de passe, connexion.
        $verified = $this->call('POST', '/api/v1/auth/activation/verify', [], ['identifier' => self::PHONE, 'code' => (string) $this->sms->lastCode(self::PHONE)]);
        self::assertSame(200, $verified->getStatusCode());
        $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $this->json($verified)['ticket'], 'password' => 'Mangue-Verte-Soleil-77']);
        self::assertSame(200, $this->login(self::PHONE, 'Mangue-Verte-Soleil-77')->getStatusCode());
    }

    public function testParEmailLeCodeEstEnvoyeParMail(): void
    {
        $answer = $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', 'Awa.Candidate@Example.test');

        self::assertSame('email', $answer['channel']);
        self::assertCount(1, $this->mail->sent);
    }

    public function testReponseIdentiqueQueLeCompteExisteDejaOuNon(): void
    {
        $this->createUser('t1', 'Paul', '+237691000088', [['directeur', 'establishment', 'e1']]);

        $known = $this->registrar()->register('t1', 'e1', 'Pirate', 'Inconnu', '+237691000088');
        $unknown = $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);

        self::assertSame(array_keys($unknown), array_keys($known));
        self::assertSame([300, 60, 'sms'], [$known['expires_in'], $known['resend_in'], $known['channel']]);
        self::assertSame([], $this->sms->messagesTo('+237691000088'), 'Compte actif : aucun code envoyé, aucun signe visible.');
    }

    public function testAucuneEleveationDeDroitsSurUnCompteExistant(): void
    {
        $this->createUser('t1', 'Paul', '+237691000088', [['directeur', 'establishment', 'e1']]);
        $this->createUser('t1', 'Invite', '+237691000089', [['directeur', 'establishment', 'e1']], active: false);

        $this->registrar()->register('t1', 'e2', 'Pirate', 'Inconnu', '+237691000088');
        $this->registrar()->register('t1', 'e2', 'Pirate', 'Inconnu', '+237691000089');

        self::assertSame([['role' => 'directeur', 'scope_type' => 'establishment', 'establishment_id' => 'e1']], $this->roles('+237691000088'));
        self::assertSame([['role' => 'directeur', 'scope_type' => 'establishment', 'establishment_id' => 'e1']], $this->roles('+237691000089'));
    }

    public function testInscriptionAbandonneeRenvoieLeCodeSansDoublonDeRole(): void
    {
        $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);
        $this->clock->advance(61);
        $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);

        self::assertCount(2, $this->sms->messagesTo(self::PHONE));
        self::assertCount(1, $this->roles(self::PHONE));
        $count = $this->pdo()->query("SELECT COUNT(*) FROM users WHERE phone = '" . self::PHONE . "'");
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testFicheDejaEnregistreeSansCompteEstReutilisee(): void
    {
        $person = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$person, 't1', 'Awa', 'Candidate', self::PHONE, null, 'fr', '2026-10-01T08:00:00+01:00']);

        $this->registrar()->register('t1', 'e1', ' awa ', 'CANDIDATE', self::PHONE);

        $count = $this->pdo()->query("SELECT COUNT(*) FROM people WHERE phone = '" . self::PHONE . "'");
        self::assertSame(1, (int) $count->fetchColumn(), 'Pas de doublon de personne.');
        $owner = $this->pdo()->query("SELECT person_id FROM users WHERE phone = '" . self::PHONE . "'");
        self::assertSame($person, $owner->fetchColumn());
    }

    public function testIdentifiantOuNomInvalideEtRenvoiTropRapproche(): void
    {
        try {
            $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', 'pas-un-contact');
            self::fail('422 attendu');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        try {
            $this->registrar()->register('t1', 'e1', '', 'Candidate', self::PHONE);
            self::fail('422 attendu');
        } catch (HttpException $e) {
            self::assertArrayHasKey('first_name', $e->errors);
        }
        $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);
        try {
            $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);
            self::fail('429 attendu');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status);
        }
    }

    public function testEtablissementInconnuDuClientEstUn404(): void
    {
        $this->expectException(HttpException::class);
        $this->registrar()->register('t1', 'inconnu', 'Awa', 'Candidate', self::PHONE);
    }

    public function testLeMemeNumeroDansUnAutreClientNeMelangePasLesComptes(): void
    {
        $this->createUser('t2', 'Autre', self::PHONE, active: false);
        $this->registrar()->register('t1', 'e1', 'Awa', 'Candidate', self::PHONE);

        $statement = $this->pdo()->prepare('SELECT tenant_id FROM users WHERE phone = ? ORDER BY tenant_id');
        $statement->execute([self::PHONE]);
        self::assertSame(['t1', 't2'], $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(1, $this->sms->messagesTo(self::PHONE), 'Le code est celui du compte du client demandé.');
    }
}
