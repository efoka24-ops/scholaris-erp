<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;

/** Situe un groupe ou une inscription dans l'organisation (école, département, filière, niveau, groupe) pour le périmètre. */
final class Placement
{
    public function __construct(
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
    ) {
    }

    /** @return array{school_id: string, department_id: string, program_id: string, level_id: string, group_id: string} */
    public function group(string $tenantId, string $establishmentId, string $groupId): array
    {
        $group = $this->academic->group($tenantId, $establishmentId, $groupId) ?? throw HttpException::notFound('Groupe introuvable.');
        $program = $this->academic->program($tenantId, $establishmentId, $group->programId) ?? throw HttpException::notFound('Filière introuvable.');

        return ['school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $group->levelId, 'group_id' => $group->id];
    }

    /**
     * Inscription validée (ou la plus récente) d'un étudiant, avec son placement.
     *
     * @return array{0: EnrolmentInfo, 1: array{school_id: string, department_id: ?string, program_id: string, level_id: string, group_id: ?string}}
     */
    public function enrolment(string $tenantId, string $establishmentId, string $personId, ?string $enrolmentId = null): array
    {
        $enrolment = null;
        if ($enrolmentId !== null) {
            $enrolment = $this->students->enrolment($tenantId, $establishmentId, $enrolmentId);
            if ($enrolment !== null && $enrolment->personId !== $personId) {
                $enrolment = null;
            }
        } else {
            foreach ($this->students->enrolmentsOf($tenantId, $establishmentId, $personId) as $candidate) {
                if ($candidate->isValidated() || $candidate->status === 'suspended') {
                    $enrolment = $candidate;
                    break;
                }
            }
        }
        if ($enrolment === null) {
            throw HttpException::notFound('Inscription introuvable.');
        }
        $program = $this->academic->program($tenantId, $establishmentId, $enrolment->programId);

        return [$enrolment, [
            'school_id' => $enrolment->schoolId, 'department_id' => $program?->departmentId, 'program_id' => $enrolment->programId,
            'level_id' => $enrolment->levelId, 'group_id' => $enrolment->groupId,
        ]];
    }
}
