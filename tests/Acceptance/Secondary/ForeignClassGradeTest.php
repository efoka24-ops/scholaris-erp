<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Grades\Domain\Defaults;
use Tests\Support\Assessment\AssessmentTestCase;

/**
 * TST-09 (T948), versant socle : un enseignant qui n'a pas la classe ne peut écrire aucune note par les routes du socle (saisie,
 * lot, import par fichier joint), même s'il contourne l'écran. Le versant secondaire (contrôle d'affectation par classe et matière,
 * 403 JOURNALISÉ `secondary.grades.denied`) est éprouvé par tests/Feature/Secondary/Grades/GradesTest.
 */
final class ForeignClassGradeTest extends AssessmentTestCase
{
    public function testUnEnseignantHorsDeLaClasseNeSaisitRien(): void
    {
        $this->bootAssessment(Defaults::settings());
        $this->seedClass();
        $this->permissions->grant('teacher2', 'secondary.grades.enter');
        $this->actors->persons['teacher2'] = 't2';
        $assessment = $this->assessment($this->subject('MATH', 'subject', 4.0), 'Devoir de mathématiques');

        [$single] = $this->api('POST', '/grades', 'teacher2', ['assessment_id' => $assessment, 'person_id' => 'p1', 'value' => 12]);
        self::assertSame(403, $single->getStatusCode());
        [$batch] = $this->api('POST', '/grades/batch', 'teacher2', ['assessment_id' => $assessment, 'entries' => [['person_id' => 'p1', 'value' => 12]]]);
        self::assertSame(403, $batch->getStatusCode());
        $file = $this->upload('/grades/import?assessment_id=' . $assessment, 'teacher2', "matricule;note\nMAT-P1;12\n");
        self::assertSame(403, $file->getStatusCode());
        self::assertSame([], $this->rows('SELECT id FROM grades'), 'Aucune note enregistrée.');

        $this->grade($assessment, 'p1', ['value' => 12], 'teacher');
        self::assertCount(1, $this->rows('SELECT id FROM grades'), 'L\'enseignant de la classe saisit.');
    }
}
