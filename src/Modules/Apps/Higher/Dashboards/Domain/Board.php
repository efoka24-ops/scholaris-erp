<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Domain;

/**
 * Tableaux de bord de l'IPES (module PIL du CDC) et permission de donnée qui ouvre chacun, en plus de `higher.dashboard.read`
 * (le périmètre école, département ou filière est celui de `higher.dashboard.read`, RG-ORG-2). Les destinataires du CDC en
 * résultent : finances (promoteur, DG, comptable), saisie et réussite (direction académique, école, département), etc.
 */
final class Board
{
    public const READ = 'higher.dashboard.read';

    public const ENROLMENT = 'enrolment';
    public const FINANCE = 'finance';
    public const GRADING = 'grading';
    public const RESULTS = 'results';
    public const ATTENDANCE = 'attendance';
    public const VACATIONS = 'vacations';
    public const NATIONAL = 'national';
    public const MINESUP = 'minesup';

    /** Clé du tableau => permissions de donnée (l'une suffit). */
    public const PERMISSIONS = [
        self::ENROLMENT => ['higher.admission.read', 'higher.enrolment.read'],
        self::FINANCE => ['higher.billing.read', 'billing.manage'],
        self::GRADING => ['higher.grades.read', 'assessment.validate'],
        self::RESULTS => ['higher.grades.read', 'assessment.validate'],
        self::ATTENDANCE => ['higher.attendance.read'],
        self::VACATIONS => ['higher.vacation.read', 'higher.vacation.validate_school', 'higher.hours.validate'],
        self::NATIONAL => ['higher.deliberation.read', 'higher.national_list.export'],
        self::MINESUP => ['higher.enrolment.read', 'higher.national_list.export'],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
