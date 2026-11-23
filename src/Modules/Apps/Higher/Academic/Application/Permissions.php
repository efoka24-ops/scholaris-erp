<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

/**
 * Permissions `higher.*` utilisées par Academic (déclarées dans Policies\RoleMatrix, migrées par W0-5). Matrice du CDC,
 * « Paramétrage académique » : R02 V, R04 É (école), R07 L, R15 L ; R03 calendrier, tutelles et règlement figé.
 */
final class Permissions
{
    /** Lecture du paramétrage (R01-R04, R07, R15 ; maquettes : R05, R06, R09). */
    public const READ = ['higher.settings.read', 'higher.curriculum.read'];

    /** Écoles, départements, filières, niveaux, groupes, autorisations, règlement en brouillon et gel (R04 école, R20). */
    public const MANAGE = 'higher.settings.manage';

    /** Registre des tutelles (R03). */
    public const TUTELAGE = 'higher.tutelage.manage';

    /** Modification d'un règlement figé, avec double authentification récente (R03, RG-ACA-3). */
    public const AMEND = 'higher.regulation.amend';

    /** Calendrier académique (R03 établissement, R04 école). */
    public const CALENDAR = ['higher.calendar.manage', 'higher.settings.manage'];

    /** Lecture des registres sensibles du paramétrage (règlements, tutelles, calendrier) : lecteurs + gestionnaires. */
    public const READ_REGISTERS = ['higher.settings.read', 'higher.curriculum.read', 'higher.tutelage.manage', 'higher.regulation.amend', 'higher.calendar.manage'];

    private function __construct()
    {
    }
}
