<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Infrastructure;

use Scholaris\Modules\Apps\Higher\Internships\Application\ConventionService;
use Scholaris\Modules\Apps\Higher\Internships\Application\DefenseService;
use Scholaris\Modules\Apps\Higher\Internships\Contracts\DefenseResults;

/** Notes des soutenances clôturées d'une personne, depuis hi_defenses et hi_internships. */
final class PdoDefenseResults implements DefenseResults
{
    public function __construct(private readonly InternshipStore $store)
    {
    }

    public function closedFor(string $tenantId, string $establishmentId, string $personId): array
    {
        $out = [];
        foreach ($this->store->select(DefenseService::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'status' => DefenseService::CLOSED], 'held_on') as $row) {
            $internship = $this->store->find(ConventionService::TABLE, $tenantId, $establishmentId, (string) $row['internship_id']);
            $out[] = [
                'defense_id' => (string) $row['id'], 'internship_id' => (string) $row['internship_id'], 'person_id' => $personId,
                'academic_year_id' => (string) ($internship['academic_year_id'] ?? ''), 'program_id' => (string) $row['program_id'],
                'level_id' => (string) $row['level_id'], 'grade' => ((int) $row['grade_hundredths']) / 100, 'held_on' => (string) $row['held_on'],
            ];
        }

        return $out;
    }
}
