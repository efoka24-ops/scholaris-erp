<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Domain;

/**
 * Statut d'un élève à l'appel : P présent, A absent, R retard (avec l'heure d'arrivée). Valeurs publiées aux autres modules par
 * Contracts\AttendanceCodes (égalité vérifiée par les tests).
 */
enum AttendanceStatus: string
{
    case Present = 'P';
    case Absent = 'A';
    case Late = 'R';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
