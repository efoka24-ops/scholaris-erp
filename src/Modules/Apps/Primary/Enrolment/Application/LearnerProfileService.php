<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Profil d'un élève du primaire (EX-PRI-14) : inscription courante, classe, niveau et indicateur « redoublant », CALCULE à partir
 * des inscriptions des autres années (même niveau) : jamais saisi à la main. La classe doit être dans le périmètre de la personne.
 */
final class LearnerProfileService
{
    public const READ = ['primary.learner.read', 'primary.enrol', 'primary.settings.manage'];
    private const ENROLLED = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL, 'graduated', 'transferred'];

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function profile(Principal $principal, string $establishmentId, string $personId, ?string $academicYearId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $mine = array_values(array_filter(
            $this->enrollments->forPerson($t, $personId),
            static fn (EnrollmentInfo $e): bool => $e->establishmentId === $establishmentId,
        ));
        if ($mine === []) {
            throw HttpException::notFound('Élève introuvable dans cet établissement.');
        }
        $current = null;
        foreach ($mine as $enrollment) {
            if ($academicYearId === null || $enrollment->academicYearId === $academicYearId) {
                $current = $enrollment;
            }
        }
        if ($current === null) {
            throw HttpException::notFound('Aucune inscription de cet élève pour cette année.');
        }
        $this->access->assertReadableClass($perimeter, $current->structureId);

        $levelId = $current->structureId === null ? null : $this->tree->levelOf($t, $establishmentId, $current->structureId);
        $repeater = false;
        if ($levelId !== null) {
            foreach ($mine as $other) {
                if ($other->id === $current->id || $other->academicYearId === $current->academicYearId || $other->structureId === null) {
                    continue;
                }
                if (in_array($other->status, self::ENROLLED, true) && $this->tree->levelOf($t, $establishmentId, $other->structureId) === $levelId) {
                    $repeater = true;
                }
            }
        }
        $profile = $levelId === null ? null : $this->levels->profile($t, $establishmentId, $levelId);

        return [
            'person_id' => $personId, 'enrollment_id' => $current->id, 'academic_year_id' => $current->academicYearId, 'status' => $current->status,
            'class_structure_id' => $current->structureId, 'level_structure_id' => $levelId, 'internal_number' => $current->internalNumber,
            'repeater' => $repeater, 'is_final_level' => $profile !== null && $profile->isFinalLevel, 'is_exam_level' => $profile !== null && $profile->isExamLevel,
            'language' => $profile === null ? null : $profile->language,
        ];
    }
}
