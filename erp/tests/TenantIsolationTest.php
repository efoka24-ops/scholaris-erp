<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;

/**
 * Isolation entre etablissements.
 *
 * C'est la garantie de securite centrale de l'application : sans elle, une
 * ecole lirait les notes et les factures d'une autre. Ces tests verifient
 * qu'elle est appliquee par la couche d'acces aux donnees, et non par la
 * vigilance de chaque controleur.
 */
final class TenantIsolationTest extends TestCase
{
    public function testUneLectureNeRenvoieQueLesLignesDeLEtablissementCourant(): void
    {
        $this->createStudent($this->tenantA, 'A/001');
        $this->createStudent($this->tenantA, 'A/002');
        $this->createStudent($this->tenantB, 'B/001');

        $this->app->tenant()->set($this->tenantA);
        $students = $this->app->table('students')->get();

        $this->assertCount(2, $students, 'Seuls les eleves de A doivent remonter');

        $this->app->tenant()->set($this->tenantB);
        $this->assertCount(1, $this->app->table('students')->get(), 'Seul l eleve de B doit remonter');
    }

    public function testUnIdentifiantDUnAutreEtablissementEstIntrouvable(): void
    {
        $foreignId = $this->createStudent($this->tenantB, 'B/001');

        $this->app->tenant()->set($this->tenantA);

        // Anti-IDOR : connaitre l'identifiant ne suffit pas a lire la ligne.
        $this->assertNull(
            $this->app->table('students')->find($foreignId),
            'Un eleve d un autre etablissement ne doit pas etre lisible par son id'
        );
    }

    public function testUneRequeteSansEtablissementCourantEchoue(): void
    {
        $this->createStudent($this->tenantA, 'A/001');
        $this->app->tenant()->clear();

        // Le defaut est le refus : sans etablissement, la requete echoue au
        // lieu de renvoyer toutes les lignes de toutes les ecoles.
        $this->assertThrows(
            fn () => $this->app->table('students')->get(),
            'Une lecture scopee sans etablissement courant doit lever une erreur'
        );
    }

    public function testLeModeGlobalLeveLeFiltrePuisLeRetablit(): void
    {
        $this->createStudent($this->tenantA, 'A/001');
        $this->createStudent($this->tenantB, 'B/001');

        $tenant = $this->app->tenant();
        $tenant->set($this->tenantA);

        $total = $tenant->global(fn (): int => $this->app->table('students')->count());

        $this->assertSame(2, $total, 'Le mode global doit voir les deux etablissements');
        $this->assertSame(1, $this->app->table('students')->count(), 'Le filtre doit etre retabli ensuite');
    }

    public function testLIdentifiantDEtablissementEstRempliALaCreation(): void
    {
        $this->app->tenant()->set($this->tenantA);

        $id = $this->app->table('students')->insert([
            'matricule' => 'A/900',
            'first_name' => 'Auto',
            'last_name' => 'Scope',
            'date_of_birth' => '2011-05-05',
            'gender' => 'FEMALE',
        ]);

        $row = $this->db->selectOne('SELECT tenant_id FROM students WHERE id = :id', ['id' => $id]);

        $this->assertSame($this->tenantA, $row['tenant_id'] ?? null, 'tenant_id doit etre renseigne automatiquement');
    }

    public function testUneMiseAJourNAtteintPasLesAutresEtablissements(): void
    {
        $foreignId = $this->createStudent($this->tenantB, 'B/001', 'Original');

        $this->app->tenant()->set($this->tenantA);

        $affected = $this->app->table('students')->where('id', $foreignId)->update(['last_name' => 'Pirate']);

        $this->assertSame(0, $affected, 'Aucune ligne d un autre etablissement ne doit etre modifiee');

        $row = $this->db->selectOne('SELECT last_name FROM students WHERE id = :id', ['id' => $foreignId]);
        $this->assertSame('Original', $row['last_name'] ?? null, 'La ligne distante doit rester intacte');
    }

    public function testUneEcritureSansConditionEstRefusee(): void
    {
        $this->app->tenant()->global(function (): void {
            // Sans filtre d'etablissement ni condition, un UPDATE reecrirait
            // toutes les ecoles : la construction doit etre refusee.
            $this->assertThrows(
                fn () => $this->app->table('students')->update(['last_name' => 'X']),
                'Un UPDATE sans condition doit etre refuse'
            );

            $this->assertThrows(
                fn () => $this->app->table('students')->delete(),
                'Un DELETE sans condition doit etre refuse'
            );
        });
    }

    public function testLaPageDUnEleveDistantRenvoie404(): void
    {
        $foreignId = $this->createStudent($this->tenantB, 'B/001');

        $userId = $this->createUser($this->tenantA, 'chef@a.cm');
        $this->giveRole($userId, 'LECTEUR', ['students:read']);
        $this->actingAs($userId);

        $response = $this->request('GET', '/students/'.$foreignId);

        $this->assertSame(404, $response->status(), 'Consulter un eleve d une autre ecole doit donner 404');
    }

    public function testUnIdentifiantSqlInvalideEstRejete(): void
    {
        $this->app->tenant()->set($this->tenantA);

        // Les noms de colonnes ne peuvent pas etre lies en parametre : leur
        // filtrage est la seule protection contre l'injection par identifiant.
        $this->assertThrows(
            fn () => $this->app->table('students')->where('id; DROP TABLE students', 'x'),
            'Un nom de colonne malformé doit etre refuse'
        );

        $this->assertThrows(
            fn () => new Table($this->db, $this->app->tenant(), 'students; DROP TABLE users'),
            'Un nom de table malformé doit etre refuse'
        );
    }
}
