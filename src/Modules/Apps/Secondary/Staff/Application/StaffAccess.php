<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

/**
 * Droits du personnel et de la paie simplifiée (tous déjà accordés par les migrations des rôles, aucun droit nouveau) :
 *  - profils : lus par la direction, le censeur et l’intendant (mêmes droits que les heures), tenus par le chef d'établissement (`settings.manage`) ;
 *  - heures du mois : direction, censeur (visa du cahier de textes, emploi du temps) et intendant ;
 *  - paie : l'intendant prépare et marque payé, le chef d'établissement fixe les statuts éligibles et valide (CDC DOC-30) ;
 *  - administration du personnel du socle (FIX-SEC, défaut 8) : `staff.read` lit les profils et les heures, `staff.manage` (administrateur
 *    du client) tient aussi les profils et lit la paie. Préparation et validation des bulletins de paie restent à l'intendant et au chef.
 */
final class StaffAccess
{
    public const PROFILE_READ = self::HOURS_READ;
    public const PROFILE_MANAGE = ['secondary.settings.manage', 'staff.manage'];
    public const HOURS_READ = ['secondary.settings.read', 'secondary.settings.manage', 'secondary.syllabus.visa', 'secondary.timetable.manage', 'secondary.billing.manage', 'secondary.finance.read', 'staff.read', 'staff.manage'];
    public const PAYROLL_READ = ['secondary.finance.read', 'secondary.billing.manage', 'secondary.settings.manage', 'staff.manage'];
    public const PAYROLL_PREPARE = ['secondary.billing.manage'];
    public const PAYROLL_VALIDATE = ['secondary.settings.manage'];

    private function __construct()
    {
    }
}
