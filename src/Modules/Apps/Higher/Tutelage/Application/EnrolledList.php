<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Inscrits validés d'une filière sous tutelle pour une année (un niveau ou tous), au format des champs de `tutelle.enrolled`. */
final class EnrolledList
{
    public function __construct(
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly PersonDirectory $people,
    ) {
    }

    /** @return list<array<string, scalar|null>> */
    public function records(string $tenantId, string $establishmentId, ProgramInfo $program, TutelageInfo $tutelage, ?string $levelId): array
    {
        $levels = [];
        foreach ($this->academic->levelsOf($tenantId, $establishmentId, $program->id) as $level) {
            if ($levelId === null || $level->id === $levelId) {
                $levels[$level->id] = $level->code;
            }
        }
        $enrolments = [];
        foreach (array_keys($levels) as $id) {
            foreach ($this->students->enrolledIn($tenantId, $establishmentId, $id, $tutelage->academicYearId) as $enrolment) {
                $enrolments[$enrolment->id] = $enrolment;
            }
        }
        $people = [];
        foreach ($this->people->findMany($tenantId, array_values(array_unique(array_map(static fn ($e): string => $e->personId, array_values($enrolments))))) as $person) {
            $people[$person->id] = $person;
        }
        $records = [];
        foreach ($enrolments as $enrolment) {
            $person = $people[$enrolment->personId] ?? null;
            $records[] = [
                'matricule' => $enrolment->matricule, 'last_name' => $person?->lastName, 'first_names' => $person?->firstName, 'gender' => $person?->gender,
                'program_code' => $program->code, 'program_name' => $program->name, 'level_code' => $levels[$enrolment->levelId] ?? null,
                'academic_year' => $enrolment->academicYearId, 'enrolment_status' => $enrolment->status, 'attempt_number' => $enrolment->attemptNumber,
                'university_name' => $tutelage->universityName,
            ];
        }
        usort($records, static fn (array $a, array $b): int => [(string) $a['level_code'], (string) $a['last_name']] <=> [(string) $b['level_code'], (string) $b['last_name']]);

        return $records;
    }
}
