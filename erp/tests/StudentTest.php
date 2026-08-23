<?php

declare(strict_types=1);

namespace Scholaris\Tests;

/**
 * Module 4 : dossiers eleves.
 */
final class StudentTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->tenantA, 'secretaire@a.cm');
        $this->giveRole($this->userId, 'SECRETAIRE', [
            'students:read', 'students:create', 'students:update', 'invoices:read',
        ]);
        $this->actingAs($this->userId);
    }

    public function testLaCreationAttribueUnMatriculeSequentiel(): void
    {
        $this->request('POST', '/students', [
            'first_name' => 'Awa',
            'last_name' => 'Ndongo',
            'date_of_birth' => '2012-03-04',
            'gender' => 'FEMALE',
        ]);

        $this->request('POST', '/students', [
            'first_name' => 'Bakari',
            'last_name' => 'Sow',
            'date_of_birth' => '2011-07-19',
            'gender' => 'MALE',
        ]);

        $matricules = array_column(
            $this->db->select('SELECT matricule FROM students ORDER BY matricule'),
            'matricule'
        );

        $year = date('Y');

        $this->assertSame(
            ["AAA/{$year}/0001", "AAA/{$year}/0002"],
            $matricules,
            'Les matricules doivent se suivre, prefixes par le code etablissement'
        );
    }

    public function testUnFormulaireIncompletNeCreeRien(): void
    {
        // Date de naissance et sexe manquants : la validation doit refuser.
        $this->request('POST', '/students', ['first_name' => 'Sans', 'last_name' => 'Naissance']);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM students'),
            'Aucun eleve ne doit etre cree a partir d un formulaire invalide'
        );
    }

    public function testLaModificationEnregistreLesChangements(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/010', 'Avant');

        $this->request('POST', '/students/'.$studentId, [
            'first_name' => 'Prenom',
            'last_name' => 'Apres',
            'date_of_birth' => '2010-01-01',
            'gender' => 'MALE',
            'status' => 'ACTIVE',
        ]);

        $row = $this->db->selectOne('SELECT last_name FROM students WHERE id = :id', ['id' => $studentId]);

        $this->assertSame('Apres', $row['last_name'] ?? null, 'Le nom doit avoir ete mis a jour');
    }

    public function testLArchivageEstUneSuppressionLogique(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/020');

        $this->request('POST', '/students/'.$studentId.'/delete');

        $row = $this->db->selectOne('SELECT deleted_at FROM students WHERE id = :id', ['id' => $studentId]);

        // La ligne doit subsister : notes, factures et bulletins deja emis y
        // font reference et doivent rester consultables.
        $this->assertTrue($row !== null, 'La ligne ne doit pas etre supprimee physiquement');
        $this->assertTrue(($row['deleted_at'] ?? null) !== null, 'deleted_at doit etre renseigne');

        $visible = $this->app->table('students')->notDeleted()->count();
        $this->assertSame(0, $visible, 'L eleve archive ne doit plus apparaitre dans les listes');
    }

    public function testLaRechercheFiltreSurNomEtMatricule(): void
    {
        $this->createStudent($this->tenantA, 'A/100', 'Mbarga');
        $this->createStudent($this->tenantA, 'A/200', 'Tchoumi');

        $html = $this->request('GET', '/students?q=Mbarga')->content();

        $this->assertStringContains('Mbarga', $html, 'Le resultat recherche doit apparaitre');
        $this->assertTrue(! str_contains($html, 'Tchoumi'), 'Les autres eleves doivent etre filtres');
    }

    public function testLaRechercheNInterpretePasLesJokersSaisis(): void
    {
        $this->createStudent($this->tenantA, 'A/300', 'Normal');

        // Un "%" saisi par l'utilisateur doit etre traite comme un caractere
        // litteral, sans quoi il ramenerait toute la table.
        $html = $this->request('GET', '/students?q=%')->content();

        $this->assertTrue(
            ! str_contains($html, 'Normal'),
            'Un joker saisi ne doit pas se comporter comme un caractere special'
        );
    }

    public function testLaFicheAfficheLesInscriptionsDeLEleve(): void
    {
        $studentId = $this->createStudent($this->tenantA, 'A/400', 'Fiche');

        $response = $this->request('GET', '/students/'.$studentId);

        $this->assertSame(200, $response->status(), 'La fiche doit etre accessible');
        $this->assertStringContains('A/400', $response->content(), 'Le matricule doit etre affiche');
    }
}
