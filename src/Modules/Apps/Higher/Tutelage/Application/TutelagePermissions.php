<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Application;

/** Permissions et paramètres de la tutelle (RoleMatrix : R03 directeur académique, R07 scolarité, R22 université de tutelle). */
final class TutelagePermissions
{
    /** Registre et suivi des relations avec la tutelle : R03. */
    public const MANAGE = 'higher.tutelage.manage';
    /** Transmissions et dossiers de diplôme : R07 et R03 (migration 202610151300_higher_nationalexams_permissions). */
    public const TRANSMIT = 'higher.tutelage.transmit';
    /** Lecture par la tutelle elle-même (R22, externe, limitée à ses filières, journalisée) : EX-TUT-04 (Could). */
    public const EXTERNAL_READ = 'higher.tutelage.read';
    public const INTERNAL = [self::MANAGE, self::TRANSMIT];

    /** Accès direct de la tutelle (decisions R4 : désactivé en V1). */
    public const SETTING_PORTAL = 'higher.tutelage.portal_enabled';
    /** Seuil d'affichage « convention bientôt expirée » (les alertes NOT-15 sont envoyées par Academic, ExpiryAlertService). */
    public const EXPIRY_WARNING_DAYS = 90;

    private function __construct()
    {
    }
}
