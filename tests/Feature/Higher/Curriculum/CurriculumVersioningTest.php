<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Curriculum;

use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\GradeProbe;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Tests\Support\Higher\Academic\CurriculumTestCase;

/**
 * RG-MAQ-1 (T215, T220) : une maquette publiée ne se modifie pas sur place ; avec des notes saisies, seule une nouvelle version
 * est possible ; à sa publication l'ancienne est « remplacée » et les étudiants inscrits sur elle y restent. EX-MAQ-04.
 */
final class CurriculumVersioningTest extends CurriculumTestCase
{
    protected function setUp(): void
    {
        $this->bootCurriculum();
    }

    public function testAvecNotesNouvelleVersionObligatoireEtAncienneConservee(): void
    {
        $v1 = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/publish');
        $directory = $this->service(CurriculumDirectory::class);
        $directory->markGradesRecorded(self::T, self::E, $v1['id']);
        $directory->markGradesRecorded(self::T, self::E, $v1['id']);

        [$status, $problem] = $this->call('PATCH', '/higher/curriculum-units/' . $v1['units']['UE2'], ['title' => 'Maths']);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-MAQ-1', (string) $problem['detail']);
        self::assertSame(409, $this->call('POST', '/higher/curricula/' . $v1['id'] . '/unpublish')[0]);
        self::assertSame(409, $this->call('POST', '/higher/curriculum-units/' . $v1['units']['UE2'] . '/elements', ['code' => 'X', 'title' => 'X', 'credits' => 1, 'cm_hours' => 1])[0]);

        $v2 = $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/versions');
        self::assertSame(['draft', 2, $v1['id']], [$v2['status'], $v2['version'], $v2['previous_version_id']]);
        self::assertSame(409, $this->call('POST', '/higher/curricula/' . $v1['id'] . '/versions')[0], 'Une seule nouvelle version en brouillon.');
        $tree = $this->ok('GET', '/higher/curricula/' . $v2['id']);
        self::assertCount(5, $tree['semesters'][0]['units']);
        $ue2 = $tree['semesters'][0]['units'][1];
        $this->ok('PATCH', '/higher/curriculum-units/' . $ue2['id'], ['title' => 'Mathématiques appliquées']);

        $this->ok('POST', '/higher/curricula/' . $v2['id'] . '/publish');

        self::assertSame('superseded', $this->ok('GET', '/higher/curricula/' . $v1['id'])['status']);
        self::assertSame($v2['id'], $directory->publishedFor(self::T, self::E, $this->org['program'], $this->org['l1'], self::YEAR)?->id);
        $old = $directory->find(self::T, self::E, $v1['id']);
        self::assertSame(['superseded', 1, true], [$old?->status, $old->version, $old->gradesRecorded], 'Les étudiants de la version 1 y restent.');
        self::assertSame('Unité UE2', $directory->units(self::T, self::E, $v1['id'])[1]->title);
        self::assertSame('Mathématiques appliquées', $directory->units(self::T, self::E, $v2['id'])[1]->title);
        $catalog = $this->service(CurriculumCatalog::class);
        self::assertTrue($catalog->find(self::T, self::E, 'hi_ue:' . $v1['units']['UE2'])?->active, 'Matière de la version 1 conservée pour ses notes.');
        self::assertNotSame($catalog->find(self::T, self::E, 'hi_ue:' . $v1['units']['UE2'])?->id, $catalog->find(self::T, self::E, 'hi_ue:' . $ue2['id'])?->id);
    }

    public function testSansNoteDepublierModifierRepublierEstIdempotent(): void
    {
        $v1 = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/publish');
        $catalog = $this->service(CurriculumCatalog::class);
        $subject = $catalog->find(self::T, self::E, 'hi_ue:' . $v1['units']['UE1'])?->id;

        self::assertSame(409, $this->call('PATCH', '/higher/curricula/' . $v1['id'], ['title' => 'X'])[0], 'Publiée : pas de modification sur place.');
        self::assertSame('draft', $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/unpublish')['status']);
        self::assertFalse($catalog->find(self::T, self::E, 'hi_ue:' . $v1['units']['UE1'])?->active, 'Matières désactivées, jamais supprimées.');
        $this->ok('PATCH', '/higher/curriculum-elements/' . $v1['elements']['CG'], ['title' => 'Comptabilité générale I']);
        $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/publish');

        $again = $catalog->find(self::T, self::E, 'hi_ue:' . $v1['units']['UE1']);
        self::assertSame([$subject, true], [$again?->id, $again->active], 'Même matière réactivée (clé externe).');
        self::assertSame('Comptabilité générale I', $catalog->elementsOf(self::T, self::E, 'hi_ue:' . $v1['units']['UE1'])[0]->name);
    }

    public function testUneSondeDeNotesBloqueAussiLaDepublication(): void
    {
        $v1 = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $v1['id'] . '/publish');
        $probe = new class ($v1['id']) implements GradeProbe {
            public function __construct(private readonly string $id)
            {
            }

            public function hasGrades(string $tenantId, string $establishmentId, string $curriculumId): bool
            {
                return $curriculumId === $this->id;
            }
        };
        $this->kernel->container()->set(GradeProbe::PREFIX . 'test', [$probe]);

        self::assertSame(409, $this->call('POST', '/higher/curricula/' . $v1['id'] . '/unpublish')[0]);
        self::assertTrue($this->service(CurriculumDirectory::class)->find(self::T, self::E, $v1['id'])?->gradesRecorded);
    }
}
