<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

/**
 * Droits du personnel et de la paie simplifiée (tous déjà accordés par la migration des rôles du secondaire, aucun droit nouveau) :
 *  - profils : lus par la direction, le censeur et l’intendant (mêmes droits que les heures), tenus par le chef d'établissement (`settings.manage`) ;
 *  - heures du mois : direction, censeur (visa du cahier de textes, emploi du temps) et intendant ;
 *  - paie : l'intendant prépare et marque payé, le chef d'établissement fixe les statuts éligibles et valide (CDC DOC-30).
 */
final class StaffAccess
{
    public const PROFILE_READ = self::HOURS_READ;
    public const PROFILE_MANAGE = ['secondary.settings.manage'];
    public const HOURS_READ = ['secondary.settings.read', 'secondary.settings.manage', 'secondary.syllabus.visa', 'secondary.timetable.manage', 'secondary.billing.manage', 'secondary.finance.read'];
    public const PAYROLL_READ = ['secondary.finance.read', 'secondary.billing.manage', 'secondary.settings.manage'];
    public const PAYROLL_PREPARE = ['secondary.billing.manage'];
    public const PAYROLL_VALIDATE = ['secondary.settings.manage'];

    private function __construct()
    {
    }
}
