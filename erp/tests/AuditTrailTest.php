<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Audit\AuditTrail;

/**
 * Journal des actes, avec valeur avant et valeur apres.
 *
 * Savoir qu'une note a ete modifiee ne sert a rien si l'on ignore ce qu'elle
 * valait : « mathematiques : 12 vers 14 » se conteste et se verifie,
 * « note modifiee » ne se conteste pas. Les colonnes existaient depuis
 * l'origine du schema mais n'etaient jamais renseignees.
 */
final class AuditTrailTest extends TestCase
{
    private function trail(): AuditTrail
    {
        return new AuditTrail($this->app);
    }

    public function testUneModificationConserveLAncienneEtLaNouvelleValeur(): void
    {
        $this->trail()->changed(
            'grade.update',
            'grades',
            'abc',
            ['value' => 12],
            ['value' => 14],
            ['value']
        );

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'grade.update'");

        $this->assertTrue($entry !== null, 'L acte est journalise');
        $this->assertStringContains('12', (string) $entry['old_value'], 'La valeur d avant est conservee');
        $this->assertStringContains('14', (string) $entry['new_value'], 'Et celle d apres');
    }

    public function testSeulesLesDifferencesSontConservees(): void
    {
        // Enregistrer la ligne entiere a chaque modification rend le journal
        // illisible et masque le seul champ qui a reellement change.
        $this->trail()->changed(
            'grade.update',
            'grades',
            'abc',
            ['value' => 12, 'is_absent' => 0, 'comment' => 'inchange'],
            ['value' => 14, 'is_absent' => 0, 'comment' => 'inchange'],
            ['value', 'is_absent', 'comment']
        );

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'grade.update'");
        $new = json_decode((string) $entry['new_value'], true);

        $this->assertSame(['value'], array_keys($new), 'Seul le champ modifie figure au journal');
    }

    public function testUneValeurIdentiqueNeCreeAucuneEntree(): void
    {
        // Un formulaire renvoye sans changement ne doit pas remplir le journal :
        // il deviendrait inexploitable pour instruire une contestation.
        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => 14], ['value' => 14], ['value']);

        $count = (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'grade.update'");

        $this->assertSame(0, $count, 'Aucune entree pour une modification qui n en est pas une');
    }

    public function testUnNombreReluEnBaseNEstPasVuCommeUneModification(): void
    {
        // Une note relue vaut « 14.00 » la ou le formulaire envoie « 14 ». Les
        // distinguer noierait les vraies modifications sous du bruit.
        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => '14.00'], ['value' => 14.0], ['value']);

        $count = (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'grade.update'");

        $this->assertSame(0, $count, '14,00 et 14 sont la meme note');
    }

    public function testUnPassageAVideEstUneModification(): void
    {
        // Effacer une note est un acte au moins aussi sensible que la changer.
        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => 14], ['value' => null], ['value']);

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'grade.update'");

        $this->assertTrue($entry !== null, 'La suppression d une note est journalisee');
    }

    public function testUneCreationNaPasDeValeurPrecedente(): void
    {
        $this->trail()->created('grade.create', 'grades', 'abc', ['value' => 15]);

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'grade.create'");

        $this->assertTrue($entry['old_value'] === null, 'Rien ne precede une creation');
        $this->assertStringContains('15', (string) $entry['new_value'], 'La valeur creee est conservee');
    }

    public function testLeJournalRetientSonAuteur(): void
    {
        $userId = $this->createUser($this->tenantA, 'prof@a.cm');
        $this->actingAs($userId);

        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => 8], ['value' => 18], ['value']);

        $entry = $this->db->selectOne("SELECT * FROM audit_logs WHERE action = 'grade.update'");

        $this->assertSame($userId, (string) $entry['user_id'], 'Un acte se rapporte a quelqu un');
    }

    public function testLeJournalNeFaitJamaisEchouerLActeQuIlEnregistre(): void
    {
        // Une base momentanement indisponible ne doit pas empecher un
        // enseignant de saisir ses notes : la trace est importante, la saisie
        // l'est davantage.
        $this->db->execute('DROP TABLE audit_logs');

        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => 12], ['value' => 14], ['value']);

        $this->assertTrue(true, 'L absence du journal ne leve aucune exception');
    }

    public function testLeJournalAfficheLaModificationLisiblement(): void
    {
        // Le JSON brut est illisible pour qui instruit une contestation, et
        // c'est pourtant a lui qu'on demande de trancher.
        $superAdmin = $this->createUser($this->tenantA, 'super@a.cm');
        $this->giveRole($superAdmin, 'SUPER_ADMIN', ['tenants:read']);
        $this->actingAs($superAdmin);

        $this->trail()->changed('grade.update', 'grades', 'abc', ['value' => 12], ['value' => 14], ['value']);

        $content = $this->request('GET', '/admin/journal')->content();

        $this->assertStringContains('Note modifiee', $content, 'L acte porte un libelle lisible');
        $this->assertStringContains('&rarr;', $content, 'La modification est presentee comme un passage');
        $this->assertStringContains('12', $content, 'Avec la valeur d avant');
        $this->assertStringContains('14', $content, 'Et celle d apres');
    }
}
