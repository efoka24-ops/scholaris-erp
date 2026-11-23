<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

/** Permissions et clés des examens nationaux BTS et HND (RoleMatrix : R02 direction générale, R07 scolarité). */
final class NationalExamsPermissions
{
    /** Sessions, liste des candidats, pièces, convocations, import des résultats, attestations : R07. */
    public const MANAGE = 'higher.national_list.manage';
    /** Export de la liste au format MINESUP (API-17), 2FA récente : R07, R02. */
    public const EXPORT = 'higher.national_list.export';
    /** Lecture : qui gère ou exporte la liste. */
    public const READ = [self::MANAGE, self::EXPORT];

    /** Rôle R02, destinataire des alertes (RG-EXN-2, T332). */
    public const ROLE_GENERAL_DIRECTOR = 'direction_groupe';
    public const ALERT_EXPORTED = 'higher.national_list_exported';
    public const ALERT_CHANGED = 'higher.national_list_changed';

    /** Paramètres : seuil d'assiduité (lu par Attendance), format d'export par défaut, âge maximal de la 2FA. */
    public const SETTING_FORMAT = 'higher.national_exams.export_format';
    public const SETTING_SECOND_FACTOR_AGE = 'higher.national_exams.second_factor_max_age';

    private function __construct()
    {
    }
}
