<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Tests\Support\Assessment\AssessmentTestCase;
use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;

/** Exports CSV des notes et des résultats : filtres, droits identiques à la liste, isolation. */
final class ExportTest extends AssessmentTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    /** @var array{math: string, french: string, math_devoir: string, french_devoir: string} */
    private array $class;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->seedClass();
        $this->class = $this->gradedClass(true);
        $this->api('POST', '/results/publish', 'censor', ['structure_id' => 'c1', 'period_id' => 'seq1', 'period_label' => 'Séquence 1']);
        $this->people->add('t1', 'parent1', 'Marie', 'Abega');
        $this->people->link('t1', 'parent1', 'p1');
        $this->actors->persons['parent'] = 'parent1';
    }

    /** @return array<string, string> */
    private function as(string $user, string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testFormatExcelPourNotesEtResultats(): void
    {
        self::assertCount(9, $this->xlsxRows('/grades/export?format=xlsx', $this->as('reader')));
        self::assertCount(5, $this->xlsxRows('/results/export?format=xlsx&filter[structure_id]=c1', $this->as('reader')));
        self::assertCount(2, $this->xlsxRows('/results/export?format=xlsx', $this->as('parent')));
        self::assertSame(403, $this->fetch('/grades/export?format=xlsx', $this->as('parent'))->getStatusCode());
    }
    public function testExportDesNotesAvecFiltre(): void
    {
        $all = $this->csvRows('/grades/export', $this->as('reader'));
        self::assertCount(9, $all, 'En-tête + 4 élèves x 2 évaluations.');
        self::assertSame(['Identifiant', 'Évaluation', 'Apprenant', 'Note'], array_slice($all[0], 0, 4));

        $one = $this->csvRows('/grades/export?filter[assessment_id]=' . $this->class['math_devoir'] . '&filter[person_id]=p1', $this->as('reader'));
        self::assertCount(2, $one);
        self::assertSame('16', $one[1][3]);
        self::assertSame(422, $this->fetch('/grades/export?filter[x]=1', $this->as('reader'))->getStatusCode());
    }

    public function testExportDesResultatsPersonnelEtFamille(): void
    {
        $staff = $this->csvRows('/results/export?filter[structure_id]=c1', $this->as('reader'));
        self::assertCount(5, $staff);
        self::assertContains('p3', array_column($staff, 1));

        $family = $this->csvRows('/results/export', $this->as('parent'));
        self::assertCount(2, $family, 'Le parent n\'exporte que les résultats publiés de son enfant.');
        self::assertSame('p1', $family[1][1]);
    }

    public function testLaLectureDeLaListeEstExigeePourLesNotes(): void
    {
        self::assertSame(403, $this->fetch('/grades/export', $this->as('parent'))->getStatusCode());
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        foreach (['/grades/export', '/results/export'] as $path) {
            self::assertCount(1, $this->csvRows($path, $this->as('reader', 't1', 'e2')), $path . ' : autre établissement');
            self::assertCount(1, $this->csvRows($path, $this->as('reader', 't2', 'e1')), $path . ' : autre client');
        }
    }
}
