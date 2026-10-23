<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Tests\Support\People\EnrolmentExtensions;
use Tests\Support\People\OneEstablishment;
use Tests\Support\People\PeopleTestCase;

/**
 * Points d'extension de l'inscription (vague 0 du Supérieur) : garde-fou d'application (D3), numéro d'inscription d'application (D5),
 * double cursus (D7). Sans application enregistrée ni paramètre : comportement du socle inchangé (voir aussi EnrollmentTest).
 */
final class EnrollmentExtensionPointsTest extends PeopleTestCase
{
    private EnrolmentExtensions $app;

    /** @var array<string, mixed> */
    private array $learner = [];

    protected function setUp(): void
    {
        $this->app = new EnrolmentExtensions();
        $this->bootPeople([
            'organisation.enrolment.guards.test' => [$this->app],
            'people.enrollment_number.providers.test' => [$this->app],
            EstablishmentDirectory::class => new OneEstablishment('higher'),
        ]);
        $this->learner = $this->person('Marie', 'Ngo', ['birth_date' => '2004-03-02', 'birth_place' => 'Yaoundé', 'gender' => 'female', 'nationality' => 'Camerounaise']);
        $this->seed('higher');
        $this->structures->open = ['track-cf', 'level-l1', 'group-a', 'track-gl', 's1'];
    }

    /** Établissement e1 et arbre filière > niveau > groupe, semés directement (tables d'Organisation, base de test). */
    private function seed(string $type): void
    {
        $pdo = $this->connections()->pdo();
        $now = '2026-10-08T09:00:00+01:00';
        $pdo->prepare('INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['e1', 't1', 'Institut', $type, '{}', '[]', $now, $now]);
        $insert = $pdo->prepare('INSERT INTO pedagogical_structures (id, tenant_id, establishment_id, org_unit_id, parent_id, kind, name, capacity, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?)');
        foreach ([
            ['track-cf', null, 'track', 'Comptabilité et finance'],
            ['level-l1', 'track-cf', 'level', 'Licence 1'],
            ['group-a', 'level-l1', 'group', 'Groupe A'],
            ['track-gl', null, 'track', 'Génie logiciel'],
            ['s1', null, 'class', '6e A'],
        ] as [$id, $parent, $kind, $name]) {
            $insert->execute([$id, 't1', 'e1', $parent, $kind, $name, $now, $now]);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function enrol(string $structure, string $year = 'y2026'): array
    {
        [, $draft] = $this->api('POST', '/enrollments', body: ['person_id' => $this->learner['id'], 'structure_id' => $structure, 'academic_year_id' => $year]);
        if (!isset($draft['data']['id'])) {
            return [409, $draft];
        }
        [$response, $body] = $this->api('POST', '/enrollments/' . $draft['data']['id'] . '/validate');

        return [$response->getStatusCode(), $body];
    }

    public function testLeGardeRecoitNiveauFiliereAnneeEtPersonne(): void
    {
        [$status, $body] = $this->enrol('group-a');

        self::assertSame(200, $status, json_encode($body, JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->app->guarded);
        $request = $this->app->guarded[0];
        self::assertSame(['t1', 'e1', 'higher', 'group-a', 'y2026', $this->learner['id'], 'level-l1', 'track-cf'], [
            $request->tenantId, $request->establishmentId, $request->establishmentType, $request->structureId,
            $request->academicYearId, $request->personId, $request->levelId, $request->trackId,
        ]);
        self::assertSame($body['data']['id'], $request->enrollmentId);
    }

    public function testLeGardeRefuseLInscriptionAvecSonMotif(): void
    {
        $this->app->refusal = 'Aucune autorisation MINESUP pour ce niveau en 2026-2027.';

        [$status, $body] = $this->enrol('group-a');

        self::assertSame(422, $status);
        self::assertSame(['Aucune autorisation MINESUP pour ce niveau en 2026-2027.'], $body['errors']['structure_id']);
        self::assertSame('draft', $this->connections()->pdo()->query('SELECT status FROM enrollments')->fetchColumn(), 'Le brouillon reste un brouillon.');
    }

    public function testLeNumeroDeLApplicationRemplaceCeluiDuSocle(): void
    {
        [$status, $body] = $this->enrol('group-a');

        self::assertSame(200, $status);
        self::assertSame('26EC-CF-0042', $body['data']['internal_number']);
        $stored = $this->connections()->pdo()->query("SELECT value FROM person_identifiers WHERE kind = 'internal'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['26EC-CF-0042'], $stored);
        self::assertSame(0, (int) $this->connections()->pdo()->query('SELECT COUNT(*) FROM number_sequences')->fetchColumn(), 'La séquence MAT du socle n\'est pas consommée.');
    }

    public function testSansFournisseurConcerneLeNumeroDuSocleEstConserve(): void
    {
        $this->app->types = ['nursery'];

        [$status, $body] = $this->enrol('group-a');

        self::assertSame(200, $status);
        self::assertSame('MAT-2026-00001', $body['data']['internal_number']);
    }

    public function testDoubleCursusRefuseParDefaut(): void
    {
        self::assertSame(200, $this->enrol('group-a')[0]);
        [$status, $body] = $this->enrol('track-gl');

        self::assertSame(409, $status);
        self::assertStringContainsString('dans cet établissement pour cette année', (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function testDoubleCursusPermisParFiliereAvecUnSeulMatricule(): void
    {
        $this->settings->values['enrolment.allow_multiple_tracks'] = true;

        [$first, $a] = $this->enrol('group-a');
        [$second, $b] = $this->enrol('track-gl');
        self::assertSame([200, 200], [$first, $second], json_encode($b, JSON_THROW_ON_ERROR));
        self::assertSame($a['data']['internal_number'], $b['data']['internal_number'], 'Un compte, un matricule, deux parcours (RG-ORG-6).');
        self::assertSame(1, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM person_identifiers WHERE kind = 'internal'")->fetchColumn());

        [$third, $c] = $this->enrol('level-l1');
        self::assertSame(409, $third, 'Même filière (le niveau L1 appartient à Comptabilité et finance) : refusé.');
        self::assertStringContainsString('dans cette filière', (string) json_encode($c, JSON_UNESCAPED_UNICODE));

        self::assertSame(200, $this->enrol('level-l1', 'y2027')[0], 'Autre année : permis.');
    }
}
