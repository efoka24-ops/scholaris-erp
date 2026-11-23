<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\NationalExams;

use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\NationalExams\NationalExamsTestCase;

/** Liste des candidats : TST-09 (RG-EXN-1), éligibilité (assiduité 70 %, frais, pièces), constitution, isolation et périmètre. */
final class CandidateListTest extends NationalExamsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assign(Org::TENANT_A, 'scol', Org::ESTABLISHMENT_A, 'higher.national_list.manage', 'establishment');
        $this->assign(Org::TENANT_A, 'it', Org::ESTABLISHMENT_A, 'higher.national_list.manage', 'org_unit', 'IT');
        $this->assign(Org::TENANT_B, 'scol-b', Org::ESTABLISHMENT_B, 'higher.national_list.manage', 'establishment');
    }

    public function testTst09UnEtudiantHorsAnneeDeSortieEstRefuse(): void
    {
        $session = $this->createSession();
        self::assertSame('CF-L2', $session['level_id']);
        foreach (['p2' => 'niveau non final', 'p3' => 'inscription non validée', 'd1' => 'autre spécialité', 'externe' => 'candidat externe'] as $person => $case) {
            [$response, $body] = $this->api('POST', '/higher/national-sessions/' . $session['id'] . '/candidates', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['person_id' => $person]);
            self::assertSame(422, $response->getStatusCode(), $case);
            self::assertStringContainsString('RG-EXN-1', (string) $body['detail'], $case);
        }
        [$response, $body] = $this->api('POST', '/higher/national-sessions/' . $session['id'] . '/candidates', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['person_id' => 'p1']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('complete', $body['status']);
        [$response] = $this->api('POST', '/higher/national-sessions/' . $session['id'] . '/candidates', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['person_id' => 'p1']);
        self::assertSame(409, $response->getStatusCode());
    }

    public function testLAssiduiteDe70PourCentLesFraisEtLesPiecesDecidentDuDossierComplet(): void
    {
        $session = $this->createSession('scol', 25000, ['acte_naissance', 'photo']);
        [, $generated] = $this->api('POST', '/higher/national-sessions/' . $session['id'] . '/candidates/generate', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(2, $generated['added']);
        [, $list] = $this->api('GET', '/higher/national-sessions/' . $session['id'] . '/candidates', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        $byPerson = array_column($list['data'], null, 'person_id');
        self::assertSame(['p1', 'p4'], array_keys($byPerson));
        self::assertFalse($byPerson['p4']['attendance_ok']);

        foreach (['p1' => 'complete', 'p4' => 'eligible'] as $person => $expected) {
            [$response, $body] = $this->api('PATCH', '/higher/national-candidates/' . $byPerson[$person]['id'], 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, [
                'pieces_received' => ['acte_naissance', 'photo'], 'fee_receipt' => 'REC-' . $person,
            ]);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($expected, $body['status'], $person);
        }

        [, $report] = $this->api('GET', '/higher/national-sessions/' . $session['id'] . '/eligibility?person_id=p4', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertTrue($report['rg_exn_1']);
        self::assertStringContainsString('70', (string) $report['reasons'][0]);
    }

    public function testIsolationEntreClientsEtRefusHorsPerimetre(): void
    {
        $session = $this->createSession();
        [$response] = $this->api('GET', '/higher/national-sessions/' . $session['id'], 'scol-b', Org::TENANT_B, Org::ESTABLISHMENT_B);
        self::assertSame(404, $response->getStatusCode());
        [$response] = $this->api('POST', '/higher/national-sessions/' . $session['id'] . '/candidates', 'scol-b', Org::TENANT_B, Org::ESTABLISHMENT_B, ['person_id' => 'b1']);
        self::assertSame(404, $response->getStatusCode());

        [$response] = $this->api('GET', '/higher/national-sessions/' . $session['id'], 'it', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(404, $response->getStatusCode());
        [$response] = $this->api('POST', '/higher/national-sessions', 'it', Org::TENANT_A, Org::ESTABLISHMENT_A, [
            'program_id' => 'CF', 'academic_year_id' => self::YEAR, 'exam_type' => 'bts', 'attendance_from' => '2025-10-01', 'attendance_to' => '2026-06-30',
        ]);
        self::assertSame(403, $response->getStatusCode());
        [$response] = $this->api('GET', '/higher/national-sessions', 'nobody', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testLeTypeDExamenSuitLeRegimeDeLaFiliere(): void
    {
        [$response] = $this->api('POST', '/higher/national-sessions', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, [
            'program_id' => 'DR', 'academic_year_id' => self::YEAR, 'exam_type' => 'hnd', 'attendance_from' => '2025-10-01', 'attendance_to' => '2026-06-30',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }
}
