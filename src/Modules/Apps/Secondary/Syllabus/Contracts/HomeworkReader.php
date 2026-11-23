<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Contracts;

/**
 * Devoirs donnés dans le cahier de textes (EX-CTX, T974), en LECTURE : portails élève, parent et enseignant. Seules les séances
 * signées portent des devoirs ; rien d'autre du cahier de textes n'est rendu.
 */
interface HomeworkReader
{
    /**
     * @param string $from borne incluse AAAA-MM-JJ (date de la séance)
     * @return list<array{session_date: string, subject_label: string, homework: string, teacher_person_id: string}> du plus récent au plus ancien
     */
    public function forClass(string $tenantId, string $establishmentId, string $classId, string $from, int $limit): array;

    /**
     * @return list<array{session_date: string, class_id: string, subject_label: string, homework: string}> devoirs donnés par l'enseignant
     */
    public function givenBy(string $tenantId, string $establishmentId, string $teacherPersonId, string $from, int $limit): array;
}
