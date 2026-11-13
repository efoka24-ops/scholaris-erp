<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceSettings;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceCodes;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;

/** DailyPresence sur les lignes d'appel des séances validées ; classe chaque séance en demi-journée selon son mode (clé `dp:` ou créneau). */
final class PdoDailyPresence implements DailyPresence
{
    public function __construct(private readonly Db $db, private readonly AttendanceSettings $settings)
    {
    }

    public function absentOn(string $tenantId, string $establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): array
    {
        if ($dayPart !== null && !in_array($dayPart, [self::MORNING, self::AFTERNOON], true)) {
            throw new InvalidArgumentException('Demi-journée inconnue : « morning » ou « afternoon » attendu.');
        }
        $sql = "SELECT r.person_id, s.slot_id, s.start_time
                FROM attendance_records r JOIN attendance_sessions s ON s.id = r.session_id AND s.tenant_id = r.tenant_id
                WHERE r.tenant_id = ? AND r.establishment_id = ? AND r.session_date = ? AND r.status = 'A' AND s.status = 'validated'";
        $params = [$tenantId, $establishmentId, $date];
        if ($structureId !== null) {
            $sql .= ' AND s.structure_id = ?';
            $params[] = $structureId;
        }
        $afternoonStart = $this->settings->afternoonStartsAt($tenantId, $establishmentId);

        $absent = [];
        foreach ($this->db->all($sql . ' ORDER BY r.person_id', $params) as $row) {
            if ($dayPart === null || in_array($dayPart, $this->partsOf((string) $row['slot_id'], (string) $row['start_time'], $afternoonStart), true)) {
                $absent[(string) $row['person_id']] = true;
            }
        }

        return array_map('strval', array_keys($absent));
    }

    /** @return list<string> demi-journées couvertes par la séance */
    private function partsOf(string $slotId, string $startTime, string $afternoonStart): array
    {
        if (str_starts_with($slotId, AttendanceCodes::DAY_PART_KEY_PREFIX)) {
            return match (substr($slotId, (int) strrpos($slotId, ':') + 1)) {
                'morning' => [self::MORNING],
                'afternoon' => [self::AFTERNOON],
                'full_day' => [self::MORNING, self::AFTERNOON],
                default => [],
            };
        }

        return [$startTime < $afternoonStart ? self::MORNING : self::AFTERNOON];
    }
}
