<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Lecture seule des séances signées du cahier de textes (`se_lesson_logs`, tenu par Syllabus, API-06) : une séance est signée par son
 * enseignant (empreinte, auteur, instant) et rattachée au créneau du socle quand elle vient de l'emploi du temps. Aucun contrat de
 * Syllabus n'expose encore les séances : cette classe est le seul point de lecture de Staff, à remplacer par un contrat
 * `Syllabus\Contracts\SignedLessonLogs` lorsqu'il existera.
 */
final class SignedLessons
{
    private const TABLE = 'se_lesson_logs';

    public function __construct(private readonly Store $store)
    {
    }

    /** Séance signée de l'enseignant sur ce créneau du socle à cette date. */
    public function signedFor(string $tenantId, string $establishmentId, string $teacherPersonId, string $slotId, string $date): bool
    {
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['teacher_person_id' => $teacherPersonId, 'slot_id' => $slotId, 'session_date' => $date], 'id', 5) as $log) {
            if (trim((string) $log['content']) !== '' && trim((string) $log['signed_at']) !== '' && trim((string) $log['signature_hash']) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Minutes signées au cahier de textes par enseignant sur un mois (« AAAA-MM »).
     *
     * @return array<string, int> personne => minutes
     */
    public function minutesByTeacher(string $tenantId, string $establishmentId, string $month): array
    {
        $minutes = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'session_date', 20000, 'session_date LIKE ?', [$month . '-%']) as $log) {
            $teacher = (string) $log['teacher_person_id'];
            $minutes[$teacher] = ($minutes[$teacher] ?? 0) + (int) round((float) $log['hours'] * 60);
        }

        return $minutes;
    }
}
