<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Constantes de contrat du module (D21) : statuts d'une ligne d'appel, nature d'une absence et base de calcul des taux.
 * Les autres modules lisent ces valeurs sans dépendre du domaine d'Attendance.
 */
final class AttendanceCodes
{
    /** Statut d'une ligne d'appel. */
    public const PRESENT = 'P';
    public const ABSENT = 'A';
    public const LATE = 'R';
    public const STATUSES = [self::PRESENT, self::ABSENT, self::LATE];

    /** Nature d'une absence : justifiée (justificatif accepté) ou non justifiée. */
    public const JUSTIFIED = 'justified';
    public const UNJUSTIFIED = 'unjustified';

    /** Base des taux (paramètre attendance.base) : séances tenues (appel validé) ou séances programmées de l'emploi du temps. */
    public const BASE_HELD = 'held';
    public const BASE_PLANNED = 'planned';

    private function __construct()
    {
    }
}
