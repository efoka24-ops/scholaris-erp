<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\NationalExams;

use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Higher\NationalExams\NationalExamsTestCase;

/** API-17 et T332 (2FA, filigrane, alerte DG), RG-EXN-2 (modification après export tracée et signalée), import des résultats. */
final class ExportAndResultsTest extends NationalExamsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['higher.national_list.manage', 'higher.national_list.export'] as $permission) {
            $this->assign(Org::TENANT_A, 'scol', Org::ESTABLISHMENT_A, $permission, 'establishment');
        }
        $this->assign(Org::TENANT_A, 'manager', Org::ESTABLISHMENT_A, 'higher.national_list.manage', 'establishment');
    }

    public function testExportTraceAlerteEtModificationApresExport(): void
    {
        $session = $this->createSession();
        $base = '/higher/national-sessions/' . $session['id'];
        $this->api('POST', $base . '/candidates/generate', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);

        [$response] = $this->api('POST', $base . '/candidates/export', 'manager', Org::TENANT_A, Org::ESTABLISHMENT_A, []);
        self::assertSame(403, $response->getStatusCode(), 'permission d\'export absente');
        [$response, $body] = $this->api('POST', $base . '/candidates/export', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, []);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('second-factor', (string) $body['type']);

        $this->secondFactor->validate(Org::TENANT_A, 'scol');
        [$response, $export] = $this->api('POST', $base . '/candidates/export', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['format' => 'csv']);
        self::assertSame(200, $response->getStatusCode(), (string) json_encode($export));
        self::assertSame(1, $export['file']['rows'], 'p4 (assiduité 50 %) reste hors liste');
        $csv = (string) base64_decode((string) $export['file']['content_base64'], true);
        self::assertStringContainsString('M-P1', $csv);
        self::assertStringContainsString('Exporté par scol', $csv);
        self::assertSame(hash('sha256', $csv), $export['fingerprint']);
        self::assertSame('higher.national_list_exported', $this->messenger->sent[0]['template']);
        self::assertSame('dg-person', $this->messenger->sent[0]['person']);

        [, $list] = $this->api('GET', $base . '/candidates?filter[status]=exported', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertCount(1, $list['data']);
        [$response] = $this->api('POST', '/higher/national-candidates/' . $list['data'][0]['id'] . '/withdraw', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['reason' => 'Abandon']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('higher.national_list_changed', $this->messenger->sent[1]['template']);
        [, $events] = $this->api('GET', $base . '/events', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame('withdrawn', $events['data'][0]['event_kind']);
        self::assertTrue($events['data'][0]['after_export']);
        self::assertContains('higher.national_list.withdrawn', array_column($this->audit->entries, 'action'));
    }

    public function testImportDesResultatsEtTauxDeReussite(): void
    {
        $session = $this->createSession();
        $base = '/higher/national-sessions/' . $session['id'];
        $this->api('POST', $base . '/candidates', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['person_id' => 'p1']);
        [$response] = $this->api('POST', $base . '/results/import', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['rows' => [['matricule' => 'M-P1', 'result' => 'admis']]]);
        self::assertSame(409, $response->getStatusCode(), 'import avant export');

        $this->secondFactor->validate(Org::TENANT_A, 'scol');
        $this->api('POST', $base . '/candidates/export', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['format' => 'xlsx']);
        [$response, $convened] = $this->api('POST', $base . '/convocations', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, ['exam_centre' => 'Douala', 'number_prefix' => 'BTS-CF-']);
        self::assertSame(1, $convened['convened']);
        [$response, $import] = $this->api('POST', $base . '/results/import', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A, [
            'csv' => "candidate_number;result;mark\nBTS-CF-0001;Admis;13,5\nINCONNU;admis;12\n",
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $import['imported']);
        self::assertCount(1, $import['rejected']);
        [, $stats] = $this->api('GET', $base . '/statistics', 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertSame(100.0, (float) $stats['success_rate']);
        [, $rates] = $this->api('GET', '/higher/national-exams/success-rates?academic_year_id=' . self::YEAR, 'scol', Org::TENANT_A, Org::ESTABLISHMENT_A);
        self::assertCount(1, $rates['data']);
    }
}
