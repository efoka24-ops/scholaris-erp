<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

/**
 * Permissions `higher.*` des admissions (Policies\RoleMatrix, ligne « Admissions » : R02 L, R04 V, R05 L, R07 É, R12 É(sa
 * candidature), R15 L). Refus par défaut ; le périmètre (école, département, filière, niveau) s'applique en plus.
 */
final class AdmissionPermissions
{
    /** Lecture des campagnes, candidatures et statistiques (R01-R05, R07, R15). */
    public const READ = ['higher.admission.read', 'higher.application.manage', 'higher.admission.decide'];

    /** Campagnes, candidatures au guichet, vérification des pièces, concours, notes, classement (R07 scolarité). */
    public const MANAGE = 'higher.application.manage';

    /** Candidature en ligne par le candidat lui-même, sur sa seule candidature (R12). */
    public const SUBMIT = 'higher.application.submit';

    /** Décision motivée, dépassement des places, commission des équivalences (R04 directeur d'école). */
    public const DECIDE = 'higher.admission.decide';

    /** Paramètre : durée de validité d'un « admis sous réserve » en jours (RG-ADM-2), surchargeable par école. */
    public const CONDITIONAL_DAYS = 'higher.admission.conditional_days';
    public const DEFAULT_CONDITIONAL_DAYS = 30;

    /** Code stable du frais de dossier au catalogue des frais (S10, Higher\Billing). */
    public const DOSSIER_FEE_CODE = 'hi.dossier';

    private function __construct()
    {
    }
}
