<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Curriculum;

use Tests\Support\Higher\Academic\CurriculumTestCase;

/**
 * EX-MAQ-03 (T221) : maquette BTS/HND en matières et coefficients rattachées aux épreuves de l'examen national ; EX-MAQ-05
 * (T222) : copie d'une année à l'autre et export CSV/XLSX de la maquette (ExportsList).
 */
final class NationalExamCurriculumTest extends CurriculumTestCase
{
    protected function setUp(): void
    {
        $this->bootCurriculum('bts');
    }

    /** @return array<string, mixed> maquette BTS1 : trois matières, S1 */
    private function bts(): array
    {
        $curriculum = $this->draft(self::YEAR, null);
        foreach ([['COMPTA', 'Comptabilité', 4.0, 'E4 Comptabilité'], ['ECO', 'Économie générale', 2.0, 'E3 Économie'], ['FR', 'Expression française', 2.0, 'E1 Français']] as [$code, $title, $coefficient, $paper]) {
            $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/units', [
                'semester_ordinal' => 1, 'code' => $code, 'title' => $title, 'unit_type' => 'fundamental', 'coefficient' => $coefficient, 'national_exam_paper' => $paper,
            ]);
        }

        return $curriculum;
    }

    public function testMatieresEtCoefficientsRattacheesALExamenNational(): void
    {
        $curriculum = $this->bts();
        $check = $this->ok('GET', '/higher/curricula/' . $curriculum['id'] . '/check');
        self::assertSame([], $check['errors'], 'Pas de crédits ni d\'EC exigés en BTS.');

        $unit = $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/units', ['semester_ordinal' => 1, 'code' => 'ANG', 'title' => 'Anglais', 'unit_type' => 'transversal', 'coefficient' => 0]);
        $errors = $this->ok('GET', '/higher/curricula/' . $curriculum['id'] . '/check')['errors'];
        self::assertSame(['Matière ANG : coefficient obligatoire (BTS, HND).', 'Matière ANG : rattachez-la à une épreuve de l\'examen national (EX-MAQ-03).'], $errors);

        $this->ok('PATCH', '/higher/curriculum-units/' . $unit['id'], ['coefficient' => 1.5, 'national_exam_paper' => 'E2 Anglais']);
        $published = $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/publish');
        self::assertSame(['published', 'bts'], [$published['status'], $published['regime']]);
    }

    public function testExportCsvEtXlsx(): void
    {
        $curriculum = $this->bts();

        [$csv] = $this->api('GET', '/higher/curricula/' . $curriculum['id'] . '/export', self::ADMIN, self::T, self::E);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringStartsWith('text/csv', $csv->getHeaderLine('Content-Type'));
        $body = (string) $csv->getBody();
        self::assertStringContainsString('Semestre;"Code UE";"Intitulé UE"', $body);
        self::assertStringContainsString('S1;COMPTA;Comptabilité;fundamental;0;4;;"E4 Comptabilité"', $body);

        [$xlsx] = $this->api('GET', '/higher/curricula/' . $curriculum['id'] . '/export?format=xlsx', self::ADMIN, self::T, self::E);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringContainsString('spreadsheetml', $xlsx->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());

        self::assertSame(422, $this->call('GET', '/higher/curricula/' . $curriculum['id'] . '/export?format=pdf')[0]);
    }

    public function testCopieDUneAnneeALAutre(): void
    {
        $curriculum = $this->bts();
        $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/publish');

        $copy = $this->ok('POST', '/higher/curricula/' . $curriculum['id'] . '/copy', ['academic_year_id' => 'AY-2027']);

        self::assertSame(['draft', 1, 'AY-2027', $curriculum['id']], [$copy['status'], $copy['version'], $copy['academic_year_id'], $copy['copied_from_id']]);
        $tree = $this->ok('GET', '/higher/curricula/' . $copy['id']);
        self::assertSame(['COMPTA', 'ECO', 'FR'], array_column($tree['semesters'][0]['units'], 'code'));
        self::assertNull($tree['semesters'][0]['units'][0]['catalog_subject_id'], 'Projection seulement à la publication.');
        self::assertSame(409, $this->call('POST', '/higher/curricula/' . $curriculum['id'] . '/copy', ['academic_year_id' => 'AY-2027'])[0]);
        self::assertSame(422, $this->call('POST', '/higher/curricula/' . $curriculum['id'] . '/copy', ['academic_year_id' => self::YEAR])[0]);
    }
}
