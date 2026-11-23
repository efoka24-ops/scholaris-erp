<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\HomeworkReader;

/** Devoirs des séances signées (`se_lesson_logs.homework` non vide), bornés au client, à l'établissement et à la date. */
final class PdoHomeworkReader implements HomeworkReader
{
    public function __construct(private readonly Store $store)
    {
    }

    public function forClass(string $tenantId, string $establishmentId, string $classId, string $from, int $limit): array
    {
        return array_map(static fn (array $row): array => [
            'session_date' => (string) $row['session_date'], 'subject_label' => (string) $row['subject_label'], 'homework' => (string) $row['homework'],
            'teacher_person_id' => (string) $row['teacher_person_id'],
        ], $this->store->select('se_lesson_logs', $tenantId, $establishmentId, ['structure_id' => $classId], 'session_date DESC', max(1, $limit), "homework IS NOT NULL AND homework <> '' AND session_date >= ?", [$from]));
    }

    public function givenBy(string $tenantId, string $establishmentId, string $teacherPersonId, string $from, int $limit): array
    {
        return array_map(static fn (array $row): array => [
            'session_date' => (string) $row['session_date'], 'class_id' => (string) $row['structure_id'], 'subject_label' => (string) $row['subject_label'],
            'homework' => (string) $row['homework'],
        ], $this->store->select('se_lesson_logs', $tenantId, $establishmentId, ['teacher_person_id' => $teacherPersonId], 'session_date DESC', max(1, $limit), "homework IS NOT NULL AND homework <> '' AND session_date >= ?", [$from]));
    }
}
