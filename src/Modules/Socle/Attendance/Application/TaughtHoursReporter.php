<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;
use Throwable;

/**
 * Signale au personnel (Staff) la séance tenue quand un appel est validé. Les heures sont enregistrées « déclarées » ;
 * le rejeu est sans effet (idempotent par enseignant, créneau et date côté Staff). Un enseignant qui n'est pas membre
 * du personnel est ignoré, et une panne côté Staff ne remet jamais en cause la validation de l'appel.
 */
final class TaughtHoursReporter
{
    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly TaughtHoursRecorder $recorder,
    ) {
    }

    /**
     * @param array<string, mixed> $session séance validée
     * @return string|null identifiant de la ligne d'heures, null si rien n'a été enregistré
     */
    public function report(array $session): ?string
    {
        $tenantId = (string) $session['tenant_id'];
        $minutes = self::minutesBetween((string) $session['start_time'], (string) $session['end_time']);
        if ($minutes < 1) {
            return null;
        }
        try {
            $staffId = $this->staff->find($tenantId, (string) $session['teacher_person_id'])?->staffId;
            if ($staffId === null) {
                return null;
            }

            return $this->recorder->record($tenantId, (string) $session['establishment_id'], $staffId, (string) $session['slot_id'], (string) $session['session_date'], $minutes);
        } catch (Throwable) {
            return null;
        }
    }

    private static function minutesBetween(string $start, string $end): int
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $start, $a) !== 1 || preg_match('/^(\d{2}):(\d{2})$/', $end, $b) !== 1) {
            return 0;
        }

        return ((int) $b[1] * 60 + (int) $b[2]) - ((int) $a[1] * 60 + (int) $a[2]);
    }
}
