<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Tutelage;

use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\NationalExams\NationalExamsTestCase;

/**
 * Tutelle : RG-TUT-2 (dossier de diplôme après délibération finale signée et quitus, T306), transmissions (DOC-18, PV signé),
 * convention et échéance, accès lecture R22 journalisé et limité à ses filières, isolation entre clients.
 */
final class TutelageTest extends NationalExamsTestCase
{
    private const DECISION = ['decision_code' => 'ADM', 'decision_label' => 'Admis', 'credits_earned' => 60, 'cumulative_credits' => 180, 'diploma_file' => true, 'mention' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assign(Org::TENANT_A, 'scol', Org::ESTABLISHMENT_A, 'higher.tutelage.transmit', 'establishment');
        $this->assign(Org::TENANT_A, 'univ', Org::ESTABLISHMENT_A, 'higher.tutelage.read', 'structure', 'DR');
        $this->assign(Org::TENANT_B, 'scol-b', Org::ESTABLISHMENT_B, 'higher.tutelage.transmit', 'establishment');
    }

    public function testLeDossierDeDiplomeExigePvFinalSigneEtQuitus(): void
    {
        $payload = ['person_id' => 'd1', 'program_id' => 'DR', 'academic_year_id' => self::YEAR];
        [$response, $body] = $this->api('POST', '/higher/tutelage/diploma-files', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, $payload);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('RG-TUT-2', (string) $body['detail']);

        $this->world->decisions['d1'] = self::DECISION;
        $this->world->balances['d1'] = 15000;
        [$response, $body] = $this->api('POST', '/higher/tutelage/diploma-files', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, $payload);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Quitus', (string) $body['detail']);

        $this->world->balances['d1'] = 0;
        [$response, $file] = $this->api('POST', '/higher/tutelage/diploma-files', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, $payload);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('transmitted', $file['status']);
        self::assertSame('Université de Douala', $file['university_name']);
        [$response] = $this->api('PATCH', '/higher/tutelage/diploma-files/' . $file['id'], 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['status' => 'withdrawn']);
        self::assertSame(409, $response->getStatusCode());
        [$response, $moved] = $this->api('PATCH', '/higher/tutelage/diploma-files/' . $file['id'], 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['status' => 'available']);
        self::assertSame('available', $moved['status']);

        [$response] = $this->api('PATCH', '/higher/tutelage/diploma-files/' . $file['id'], 'scol-b', Org::TENANT_B, Org::ESTABLISHMENT_B, ['status' => 'withdrawn']);
        self::assertSame(404, $response->getStatusCode(), 'isolation entre clients');
    }

    public function testTransmissionsListeEtPvSigne(): void
    {
        [$response, $sent] = $this->api('POST', '/higher/tutelage/transmissions', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['kind' => 'lists', 'program_id' => 'DR', 'academic_year_id' => self::YEAR]);
        self::assertSame(201, $response->getStatusCode(), (string) json_encode($sent));
        self::assertSame(1, $sent['file']['rows']);
        self::assertStringContainsString('M-D1', (string) base64_decode((string) $sent['file']['content_base64'], true));

        $minutes = ['kind' => 'minutes', 'program_id' => 'DR', 'academic_year_id' => self::YEAR, 'level_id' => 'DR-L1', 'period_id' => 'S1'];
        [$response] = $this->api('POST', '/higher/tutelage/transmissions', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, $minutes);
        self::assertSame(409, $response->getStatusCode());
        $this->world->signed['DR-L1|S1'] = true;
        [$response, $ok] = $this->api('POST', '/higher/tutelage/transmissions', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, $minutes);
        self::assertSame(201, $response->getStatusCode());
        [$response, $answered] = $this->api('POST', '/higher/tutelage/transmissions/' . $ok['transmission']['id'] . '/answer', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['status' => 'acknowledged']);
        self::assertSame('acknowledged', $answered['status']);

        [$response] = $this->api('POST', '/higher/tutelage/transmissions', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['kind' => 'lists', 'program_id' => 'CF', 'academic_year_id' => self::YEAR]);
        self::assertSame(422, $response->getStatusCode(), 'filière sans tutelle');
        [, $list] = $this->api('GET', '/higher/tutelage/transmissions', 'scol-b', Org::TENANT_B, Org::ESTABLISHMENT_B);
        self::assertSame([], $list['data']);
    }

    public function testConventionEtAccesLectureDeLaTutelle(): void
    {
        $this->settings->set(Org::ESTABLISHMENT_A, 'higher.tutelage.portal_enabled', false);
        [, $convention] = $this->api('GET', '/higher/tutelage/convention?program_id=DR&academic_year_id=' . self::YEAR, 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame('CONV-12', $convention['convention_reference']);
        self::assertIsInt($convention['days_left']);

        $portal = '/higher/tutelage/portal/enrolments?program_id=DR&academic_year_id=' . self::YEAR;
        [$response] = $this->api('GET', $portal, 'univ', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(404, $response->getStatusCode(), 'accès fermé par défaut (decisions R4)');
        $this->settings->set(Org::ESTABLISHMENT_A, 'higher.tutelage.portal_enabled', true);
        [$response, $read] = $this->api('GET', $portal, 'univ', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $read['data']);
        [$response] = $this->api('GET', '/higher/tutelage/portal/enrolments?program_id=CF&academic_year_id=' . self::YEAR, 'univ', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(404, $response->getStatusCode(), 'filière hors de la tutelle');
        [, $journal] = $this->api('GET', '/higher/tutelage/consultations', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertCount(1, $journal['data']);
        self::assertSame('univ', $journal['data'][0]['user_id']);
    }
}
