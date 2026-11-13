<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

/**
 * Permissions `higher.*` et paramètres des inscriptions (Policies\RoleMatrix, ligne « Inscriptions » : R02 L, R04 V, R05 L, R07 É,
 * R12 L(la sienne), R13 L, R15 L). Refus par défaut ; périmètre école, département, filière, niveau ou groupe en plus.
 */
final class EnrolmentPermissions
{
    /** Lecture des inscriptions, dossiers étudiants, contrats pédagogiques (R01-R07, R15, R16). */
    public const READ = ['higher.enrolment.read', 'higher.enrol', 'higher.enrolment.validate'];

    /** Inscription administrative et pédagogique, réinscription, statuts, cartes et fiches (R07 scolarité). */
    public const ENROL = 'higher.enrol';

    /** Validation, inscription « sous réserve financière » (RG-INS-2), décisions de passage saisies (R04 directeur d'école). */
    public const VALIDATE = 'higher.enrolment.validate';

    /** Dérogation à la limite d'inscriptions consécutives dans un niveau (RG-INS-5, R04). */
    public const DEROGATE = 'higher.enrolment.derogate';

    /** Paramètres (lus à la portée de l'école, D6). */
    public const MATRICULE_PATTERN = 'higher.enrolment.matricule_pattern';
    public const MAX_CONSECUTIVE = 'higher.enrolment.max_consecutive';
    public const WITHDRAWAL_DEADLINE = 'higher.enrolment.withdrawal_deadline';
    public const REFUND_GRID = 'higher.enrolment.refund_grid';
    /** Paramètre du socle (D7) : double cursus, une inscription vivante par filière et par année. */
    public const MULTIPLE_TRACKS = 'enrolment.allow_multiple_tracks';

    /** Codes stables des frais (S10, Higher\Billing) : inscription (RG-INS-2) et pension (seuls frais remboursables, RG-INS-6). */
    public const REGISTRATION_FEE_CODE = 'hi.inscription';
    public const TUITION_FEE_CODE = 'hi.pension';

    public const MODES = ['day', 'evening', 'work_study'];

    private function __construct()
    {
    }
}
