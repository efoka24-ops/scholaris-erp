<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Internships;

use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumSummary;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumUnitInfo;

/** Maquette publiée d'un niveau : une UE « Stage et mémoire » au semestre 2 dont l'EC MEM reçoit la note de soutenance. */
final class FakeCurriculum implements CurriculumDirectory
{
    public function __construct(private readonly string $programId, private readonly string $levelId, private readonly string $year)
    {
    }

    public function publishedFor(string $tenantId, string $establishmentId, string $programId, string $levelId, string $academicYearId): ?CurriculumSummary
    {
        return $programId === $this->programId && $levelId === $this->levelId && $academicYearId === $this->year ? $this->find($tenantId, $establishmentId, 'cur-1') : null;
    }

    public function find(string $tenantId, string $establishmentId, string $curriculumId): ?CurriculumSummary
    {
        return new CurriculumSummary('cur-1', $this->programId, $this->levelId, $this->year, 'lmd', 1, 'published', 'Maquette L2', null, false);
    }

    public function units(string $tenantId, string $establishmentId, string $curriculumId): array
    {
        return [
            new CurriculumUnitInfo('ue-stage', 2, 'UE6', 'Stage et mémoire', 'internship', 6, 1.0, null, null, null, null, [
                ['id' => 'ec-mem', 'code' => 'MEM', 'title' => 'Mémoire', 'credits' => 6, 'coefficient' => 1.0, 'catalog_element_id' => null],
            ]),
        ];
    }

    public function markGradesRecorded(string $tenantId, string $establishmentId, string $curriculumId): void
    {
    }
}
