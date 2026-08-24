<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Ce que recoit le chef d'etablissement qui depose une demande.
 *
 * Jusqu'ici : rien. Il deposait son dossier, et n'entendait plus parler de
 * rien — ni accuse de reception, ni reponse, ni moyen de savoir ou en etait sa
 * demande. Il ne lui restait que le telephone.
 *
 * Ces tests fixent les trois moments ou la plateforme lui doit une reponse, et
 * verifient qu'un echec d'envoi ne fait jamais echouer l'action elle-meme.
 */
final class EstablishmentNotificationTest extends TestCase
{
    public function testLeDepotDonneUneReferenceEtUnAccuseDeReception(): void
    {
        $this->submitRequest('EPT', 'Ecole primaire de Test', 'chef@ept.cm');

        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE code = :code', ['code' => 'EPT']);

        $this->assertTrue($demand !== null, 'La demande est enregistree');
        $this->assertTrue(
            preg_match('/^ET-[A-Z0-9]{6}$/', (string) $demand['reference']) === 1,
            'Une reference courte, lisible au telephone, est attribuee'
        );

        $mail = $this->db->selectOne(
            'SELECT * FROM notifications WHERE context_id = :id',
            ['id' => $demand['id']]
        );

        $this->assertTrue($mail !== null, 'Un accuse de reception est adresse au demandeur');
        $this->assertSame('chef@ept.cm', (string) $mail['recipient'], 'A son adresse');
        $this->assertStringContains(
            (string) $demand['reference'],
            (string) $mail['body'],
            'Et il porte la reference du dossier'
        );
    }

    public function testDeuxDemandesNePartagentPasLaMemeReference(): void
    {
        // Deux dossiers de meme reference renverraient un demandeur vers celui
        // d'un autre, avec ses coordonnees.
        $this->submitRequest('AAA1', 'Ecole une', 'un@test.cm');
        $this->submitRequest('BBB1', 'Ecole deux', 'deux@test.cm');

        $references = array_column(
            $this->db->select('SELECT reference FROM establishment_requests'),
            'reference'
        );

        $this->assertSame(2, count(array_unique($references)), 'Les references sont distinctes');
    }

    public function testLApprobationTransmetLesIdentifiants(): void
    {
        $demandId = $this->pendingRequest('LYC', 'Lycee de Test', 'proviseur@lyc.cm');
        $this->actAsSuperAdmin();

        $response = $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $mail = $this->db->selectOne(
            "SELECT * FROM notifications WHERE context_id = :id AND subject LIKE '%ouvert%'",
            ['id' => $demandId]
        );

        $this->assertTrue($mail !== null, 'Un courrier annonce l ouverture');
        $this->assertStringContains('proviseur@lyc.cm', (string) $mail['body'], 'Il rappelle l identifiant');
        $this->assertStringContains('/login', (string) $mail['body'], 'Et l adresse de connexion');

        // Le mot de passe affiche a l'ecran doit etre celui qui a ete envoye,
        // sans quoi le destinataire ne pourrait pas se connecter.
        $shown = $this->extractPassword($response->content());

        $this->assertTrue($shown !== null, 'Le mot de passe est affiche une fois');
        $this->assertStringContains($shown, (string) $mail['body'], 'Et c est bien celui qui est transmis');
    }

    public function testLeRefusTransmetSonMotif(): void
    {
        // Un refus sans raison laisse le demandeur sans rien a corriger : il
        // redeposera le meme dossier.
        $demandId = $this->pendingRequest('REF', 'Ecole refusee', 'chef@ref.cm');
        $this->actAsSuperAdmin();

        $this->request('POST', '/admin/etablissements/'.$demandId.'/refuser', [
            'reason' => 'Autorisation ministerielle manquante',
        ]);

        $mail = $this->db->selectOne(
            "SELECT * FROM notifications WHERE context_id = :id AND subject LIKE '%non retenue%'",
            ['id' => $demandId]
        );

        $this->assertTrue($mail !== null, 'Un courrier annonce le refus');
        $this->assertStringContains(
            'Autorisation ministerielle manquante',
            (string) $mail['body'],
            'Avec le motif, pour que le dossier puisse etre corrige'
        );
    }

    public function testUnCourrierNonRemisNEmpechePasLaCreation(): void
    {
        // Le serveur de messagerie peut etre indisponible. Refuser pour autant
        // la creation de l'etablissement serait absurde : le compte existe, et
        // le mot de passe est affiche a l'ecran.
        $demandId = $this->pendingRequest('IND', 'Ecole indisponible', 'chef@ind.cm');
        $this->actAsSuperAdmin();

        $response = $this->request('POST', '/admin/etablissements/'.$demandId.'/approuver');

        $this->assertSame(200, $response->status(), 'La creation aboutit');
        $this->assertTrue(
            $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => 'IND']) !== null,
            'Et l etablissement existe'
        );

        $mail = $this->db->selectOne(
            "SELECT status FROM notifications WHERE context_id = :id AND subject LIKE '%ouvert%'",
            ['id' => $demandId]
        );

        // En test, aucune adresse d'expedition n'est configuree : le courrier
        // est journalise sans partir. L'essentiel est qu'il en reste trace.
        $this->assertTrue(
            in_array((string) $mail['status'], ['SKIPPED', 'FAILED', 'SENT'], true),
            'Le sort du courrier est consigne, quel qu il soit'
        );
    }

    // --- Suivi du dossier ----------------------------------------------------

    public function testLeDemandeurSuitSonDossierAvecSaReference(): void
    {
        $this->submitRequest('SUI', 'Ecole suivie', 'chef@sui.cm');
        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE code = :code', ['code' => 'SUI']);

        $content = $this->request('POST', '/demande-etablissement/suivi', [
            'reference' => (string) $demand['reference'],
            'email' => 'chef@sui.cm',
        ])->content();

        $this->assertStringContains('Ecole suivie', $content, 'Le dossier est retrouve');
        $this->assertStringContains('En cours d instruction', $content, 'Avec son etat');
    }

    public function testUneReferenceSeuleNeSuffitPas(): void
    {
        // Sans l'email, une reference devinee donnerait acces aux coordonnees
        // d'un tiers.
        $this->submitRequest('SEC', 'Ecole confidentielle', 'chef@sec.cm');
        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE code = :code', ['code' => 'SEC']);

        $content = $this->request('POST', '/demande-etablissement/suivi', [
            'reference' => (string) $demand['reference'],
            'email' => 'curieux@ailleurs.cm',
        ])->content();

        $this->assertTrue(
            ! str_contains($content, 'Ecole confidentielle'),
            'Une adresse qui ne correspond pas ne doit rien reveler'
        );
        $this->assertStringContains('Aucun dossier ne correspond', $content, 'La reponse reste vague');
    }

    public function testLeMotifDuRefusEstVisibleDansLeSuivi(): void
    {
        $this->submitRequest('MOT', 'Ecole motivee', 'chef@mot.cm');
        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE code = :code', ['code' => 'MOT']);

        $this->actAsSuperAdmin();
        $this->request('POST', '/admin/etablissements/'.$demand['id'].'/refuser', ['reason' => 'Dossier incomplet']);

        // La page de suivi est publique : elle reste consultable sans compte.
        $content = $this->request('POST', '/demande-etablissement/suivi', [
            'reference' => (string) $demand['reference'],
            'email' => 'chef@mot.cm',
        ])->content();

        $this->assertStringContains('Non retenue', $content, 'L etat est affiche');
        $this->assertStringContains('Dossier incomplet', $content, 'Ainsi que le motif');
    }

    public function testLaRegionSuitLeDossierJusquALEtablissement(): void
    {
        // C'est elle qui situe l'etablissement sur la carte du parc.
        $this->submitRequest('REG', 'Ecole de Garoua', 'chef@reg.cm', 'NORD');
        $demand = $this->db->selectOne('SELECT * FROM establishment_requests WHERE code = :code', ['code' => 'REG']);

        $this->assertSame('NORD', (string) $demand['region'], 'La region est enregistree avec la demande');

        $this->actAsSuperAdmin();
        $this->request('POST', '/admin/etablissements/'.$demand['id'].'/approuver');

        $region = $this->db->scalar('SELECT region FROM tenants WHERE code = :code', ['code' => 'REG']);

        $this->assertSame('NORD', (string) $region, 'Et reportee sur l etablissement cree');
    }

    public function testUneRegionInventeeEstIgnoree(): void
    {
        $this->submitRequest('INV', 'Ecole inventive', 'chef@inv.cm', 'ATLANTIDE');

        $region = $this->db->scalar(
            'SELECT region FROM establishment_requests WHERE code = :code',
            ['code' => 'INV']
        );

        $this->assertTrue($region === null, 'Une region hors des dix du pays n est pas retenue');
    }

    // --- Outillage -----------------------------------------------------------

    private function submitRequest(string $code, string $name, string $email, ?string $region = null): void
    {
        $payload = [
            'name' => $name,
            'code' => $code,
            'type' => 'PRIMAIRE',
            'status' => 'PRIVE',
            'director_first_name' => 'Chef',
            'director_last_name' => 'ETABLISSEMENT',
            'director_email' => $email,
        ];

        if ($region !== null) {
            $payload['region'] = $region;
        }

        $this->request('POST', '/demande-etablissement', $payload);
    }

    private function pendingRequest(string $code, string $name, string $email): string
    {
        $this->submitRequest($code, $name, $email);

        return (string) $this->db->scalar(
            'SELECT id FROM establishment_requests WHERE code = :code',
            ['code' => $code]
        );
    }

    private function actAsSuperAdmin(): void
    {
        $userId = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($userId, 'SUPER_ADMIN', []);
        $this->actingAs($userId);
    }

    private function extractPassword(string $html): ?string
    {
        if (preg_match('#Mot de passe provisoire</dt><dd><strong>([^<]+)#', $html, $m) === 1) {
            return trim($m[1]);
        }

        // Le gabarit peut inserer des espaces entre les balises.
        if (preg_match('#Mot de passe provisoire.*?<strong>\s*([A-Za-z0-9]{8,})\s*</strong>#s', $html, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
