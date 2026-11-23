<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Contracts;

/**
 * Affectation d'enseignement du secondaire (lecture seule) : un enseignant (permanent ou vacataire) enseigne une matière de
 * l'établissement à une classe, ou à un demi-groupe de la classe (dédoublement), pour une année.
 */
final class AssignmentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $academicYearId,
        /** structure de classe du socle */
        public readonly string $classId,
        /** null = toute la classe ; G1, G2 = demi-groupe */
        public readonly ?string $groupCode,
        /** matière de l'établissement (se_subjects) */
        public readonly string $localSubjectId,
        /** matière projetée au socle (CurriculumCatalog) */
        public readonly string $subjectId,
        public readonly string $subjectCode,
        public readonly string $subjectLabel,
        public readonly string $teacherPersonId,
        /** permanent ou vacataire */
        public readonly string $teacherKind,
        public readonly string $roomKind,
        public readonly ?string $roomId,
        /** active ou ended */
        public readonly string $status,
    ) {
    }
}
