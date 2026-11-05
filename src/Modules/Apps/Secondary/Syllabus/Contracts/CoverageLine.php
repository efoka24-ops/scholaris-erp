<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Contracts;

/**
 * Couverture du programme d'une matière dans une classe (EX-CTX-03) : heures prévues par la progression, heures faites (séances
 * signées du cahier de textes), taux = faites / prévues (null sans progression), leçons traitées.
 */
final class CoverageLine
{
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $classId,
        public readonly ?string $groupCode,
        public readonly string $localSubjectId,
        public readonly string $subjectLabel,
        public readonly string $teacherPersonId,
        public readonly ?float $plannedHours,
        public readonly float $doneHours,
        public readonly ?float $rate,
        public readonly int $lessonsTotal,
        public readonly int $lessonsDone,
        public readonly ?string $lastLogOn,
    ) {
    }
}
