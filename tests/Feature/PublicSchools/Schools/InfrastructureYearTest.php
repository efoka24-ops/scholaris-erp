<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Schools;

use Tests\Feature\PublicSchools\Pupils\PublicSchoolsTestCase;

/** EX-ECO-02/03/05 : infrastructures annuelles versionnées, version close immuable, correction = version n+1 motivée. Données SYN-*. */
final class InfrastructureYearTest extends PublicSchoolsTestCase
{
    private const YEAR = [
        'year_label' => '2026-2027',
        'desks_count' => 120,
        'water_points' => 2,
        'latrines_girls' => 4,
        'latrines_boys' => 4,
        'has_electricity' => true,
        'has_fence' => false,
        'has_canteen' => false,
        'has_library' => true,
        'network_observed' => 'limited',
        'digital_equipment' => [['label' => 'Ordinateur SYN', 'quantity' => 3]],
        'classrooms' => [
            ['label' => 'Salle 1', 'material' => 'durable', 'room_state' => 'good', 'in_use' => true],
            ['label' => 'Salle 2', 'material' => 'banco', 'room_state' => 'poor', 'in_use' => false],
        ],
    ];

    protected function setUp(): void
    {
        $this->bootPublic();
        $this->seedSchool('t1', 'e1', 'SYN-E1');
        $this->seedSchool('t2', 'e9', 'SYN-E9');
        $this->director('dir1', 't1', 'e1', 'public_schools.school.read', 'public_schools.school.write');
        $this->director('dir9', 't2', 'e9', 'public_schools.school.read', 'public_schools.school.write');
    }

    /** @return array<string, mixed> */
    private function createYear(): array
    {
        [$response, $json] = $this->api('POST', '/public/schools/current/infrastructure', 'dir1', 't1', 'e1', self::YEAR);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'];
    }

    public function testCreationBrouillonAvecSalles(): void
    {
        $year = $this->createYear();

        self::assertSame(1, $year['version']);
        self::assertSame('draft', $year['state']);
        self::assertSame(120, $year['desks_count']);
        self::assertCount(2, $year['classrooms']);
        self::assertSame('Ordinateur SYN', $year['digital_equipment'][0]['label']);
        self::assertSame(409, $this->code('POST', '/public/schools/current/infrastructure', 'dir1', 't1', 'e1', self::YEAR), 'Une seule version initiale par année.');
    }

    public function testLeBrouillonSeModifieAvecSesSalles(): void
    {
        $year = $this->createYear();
        [$response, $json] = $this->api('PATCH', '/public/schools/current/infrastructure/' . $year['id'], 'dir1', 't1', 'e1', [
            'desks_count' => 150,
            'classrooms' => [['label' => 'Salle 3', 'material' => 'durable', 'room_state' => 'good', 'in_use' => true]],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(150, $json['data']['desks_count']);
        self::assertSame(['Salle 3'], array_column($json['data']['classrooms'], 'label'));
        self::assertSame(2, $json['data']['water_points'], 'Les valeurs non fournies sont conservées.');
    }

    public function testUneVersionCloseEstImmuableEtLaCorrectionCreeLaVersionSuivante(): void
    {
        $year = $this->createYear();
        [$closed, $closedJson] = $this->api('POST', '/public/schools/current/infrastructure/' . $year['id'] . '/close', 'dir1', 't1', 'e1');
        self::assertSame(200, $closed->getStatusCode());
        self::assertSame('closed', $closedJson['data']['state']);
        self::assertSame('dir1', $closedJson['data']['closed_by']);

        self::assertSame(409, $this->code('PATCH', '/public/schools/current/infrastructure/' . $year['id'], 'dir1', 't1', 'e1', ['desks_count' => 1]));
        self::assertSame(422, $this->code('POST', '/public/schools/current/infrastructure/' . $year['id'] . '/corrections', 'dir1', 't1', 'e1', ['data' => ['desks_count' => 99]]), 'Le motif est obligatoire.');

        [$corrected, $correctedJson] = $this->api('POST', '/public/schools/current/infrastructure/' . $year['id'] . '/corrections', 'dir1', 't1', 'e1', [
            'reason' => 'Recomptage des tables-bancs',
            'data' => ['desks_count' => 99],
        ]);
        self::assertSame(201, $corrected->getStatusCode());
        self::assertSame(2, $correctedJson['data']['version']);
        self::assertSame('draft', $correctedJson['data']['state']);
        self::assertSame('Recomptage des tables-bancs', $correctedJson['data']['correction_reason']);
        self::assertSame(99, $correctedJson['data']['desks_count']);
        self::assertCount(2, $correctedJson['data']['classrooms'], 'Les salles sont reprises de la version corrigée.');

        [, $first] = $this->api('GET', '/public/schools/current/infrastructure/' . $year['id'], 'dir1', 't1', 'e1');
        self::assertSame(120, $first['data']['desks_count'], 'La version close garde ses valeurs.');
        self::assertSame(409, $this->code('POST', '/public/schools/current/infrastructure/' . $year['id'] . '/corrections', 'dir1', 't1', 'e1', ['reason' => 'x', 'data' => []]), 'Seule la dernière version se corrige.');

        [, $list] = $this->api('GET', '/public/schools/current/infrastructure?year_label=2026-2027', 'dir1', 't1', 'e1');
        self::assertSame([1, 2], array_column($list['data'], 'version'));
    }

    public function testUneCorrectionExigeUneVersionClose(): void
    {
        $year = $this->createYear();

        self::assertSame(409, $this->code('POST', '/public/schools/current/infrastructure/' . $year['id'] . '/corrections', 'dir1', 't1', 'e1', ['reason' => 'Erreur', 'data' => []]));
    }

    public function testValidation(): void
    {
        foreach ([
            ['year_label' => '2026'],
            ['desks_count' => -1],
            ['has_fence' => 'oui'],
            ['network_observed' => 'fibre'],
            ['digital_equipment' => [['label' => '', 'quantity' => 1]]],
            ['classrooms' => [['label' => 'S', 'material' => 'Durable', 'room_state' => 'good', 'in_use' => true]]],
        ] as $invalid) {
            self::assertSame(422, $this->code('POST', '/public/schools/current/infrastructure', 'dir1', 't1', 'e1', $invalid + self::YEAR), json_encode($invalid, JSON_THROW_ON_ERROR));
        }
    }

    public function testIsolationEntreClientsEtPermissions(): void
    {
        $year = $this->createYear();
        $this->teacher('maitre', 't1', 'e1', 'c1', 'public_schools.school.read', 'public_schools.school.write');

        self::assertSame(404, $this->code('GET', '/public/schools/current/infrastructure/' . $year['id'], 'dir9', 't2', 'e9'), 'Autre client : version introuvable.');
        self::assertSame([], $this->api('GET', '/public/schools/current/infrastructure', 'dir9', 't2', 'e9')[1]['data']);
        self::assertSame(404, $this->code('PATCH', '/public/schools/current/infrastructure/' . $year['id'], 'dir9', 't2', 'e9', ['desks_count' => 1]));
        self::assertSame(403, $this->code('POST', '/public/schools/current/infrastructure', 'maitre', 't1', 'e1', self::YEAR), 'Un enseignant lit la fiche mais ne l\'écrit pas.');
        self::assertSame(200, $this->code('GET', '/public/schools/current/infrastructure', 'maitre', 't1', 'e1'));
    }

    public function testEcritureRefuseeQuandLApplicationEstSuspendue(): void
    {
        $this->applications->set('t1', 'public_primary', \Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess::SUSPENDED);

        self::assertSame(423, $this->code('POST', '/public/schools/current/infrastructure', 'dir1', 't1', 'e1', self::YEAR));
        self::assertSame(200, $this->code('GET', '/public/schools/current/infrastructure', 'dir1', 't1', 'e1'));
    }
}
