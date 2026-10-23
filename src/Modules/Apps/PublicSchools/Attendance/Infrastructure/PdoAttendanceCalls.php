<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Suivi des appels de classe (pub_attendance_calls) : toujours borné par client et établissement. */
final class PdoAttendanceCalls
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** Inscrit ou met à jour l'appel d'une classe pour un jour et une demi-journée ; une correction garde l'auteur initial. */
    public function record(
        string $tenantId,
        string $establishmentId,
        string $structureId,
        string $day,
        string $dayPart,
        string $sessionId,
        int $headcount,
        int $absent,
        int $late,
        string $actorId,
        string $at,
        ?string $correctionReason,
    ): void {
        $pdo = $this->connections->pdo();
        $find = $pdo->prepare('SELECT id FROM pub_attendance_calls WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND day_on = ? AND day_part = ?');
        $find->execute([$tenantId, $establishmentId, $structureId, $day, $dayPart]);
        $existing = $find->fetchColumn();
        if ($existing === false) {
            $pdo->prepare('INSERT INTO pub_attendance_calls (id, tenant_id, establishment_id, structure_id, day_on, day_part, session_id, headcount, absent_count, late_count, taken_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $establishmentId, $structureId, $day, $dayPart, $sessionId, $headcount, $absent, $late, $actorId, $at]);

            return;
        }
        $pdo->prepare('UPDATE pub_attendance_calls SET session_id = ?, headcount = ?, absent_count = ?, late_count = ?, corrected_by = ?, corrected_at = ?, correction_reason = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$sessionId, $headcount, $absent, $late, $actorId, $at, $correctionReason, $tenantId, (string) $existing]);
    }

    /** @return list<array<string, mixed>> appels de la classe entre deux jours inclus, par jour puis demi-journée */
    public function between(string $tenantId, string $establishmentId, string $structureId, string $from, string $to): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_attendance_calls WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND day_on >= ? AND day_on <= ? ORDER BY day_on, day_part',
        );
        $query->execute([$tenantId, $establishmentId, $structureId, $from, $to]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function forDay(string $tenantId, string $establishmentId, string $structureId, string $day): array
    {
        return $this->between($tenantId, $establishmentId, $structureId, $day, $day);
    }

    /** @return array<string, true> jours (AAAA-MM-JJ) où la classe a fait au moins un appel entre deux dates incluses */
    public function daysWithCall(string $tenantId, string $establishmentId, string $structureId, string $from, string $to): array
    {
        $days = [];
        foreach ($this->between($tenantId, $establishmentId, $structureId, $from, $to) as $row) {
            $days[(string) $row['day_on']] = true;
        }

        return $days;
    }

    /** @return list<array{0: string, 1: string}> couples (client, établissement) qui ont déjà fait un appel */
    public function scopes(): array
    {
        $query = $this->connections->pdo()->prepare('SELECT DISTINCT tenant_id, establishment_id FROM pub_attendance_calls ORDER BY tenant_id, establishment_id');
        $query->execute();
        $scopes = [];
        foreach ($query->fetchAll(PDO::FETCH_NUM) as $row) {
            $scopes[] = [(string) $row[0], (string) $row[1]];
        }

        return $scopes;
    }
}
