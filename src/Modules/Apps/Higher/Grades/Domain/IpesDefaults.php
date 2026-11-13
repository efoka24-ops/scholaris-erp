<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Domain;

/**
 * Configuration IPES du moteur d'évaluation du socle (S1 à S4), déclarée pour les établissements `higher` : valeurs par défaut
 * appliquées quand l'établissement (ou l'école, RG-ORG-5) n'a pas fixé la clé `assessment.*` correspondante. Même format que
 * celui lu par le module Assessment du socle (AssessmentSettings), pour qu'un seul paramétrage gouverne les deux.
 */
final class IpesDefaults
{
    /** RG-NOT-1 : 0 à 20 au quart de point. */
    public const STEP = 0.25;
    public const MAX_SCORE = 20.0;

    public const ZERO = 'zero';
    public const EXCLUDED = 'excluded';
    public const RETAKE = 'retake';

    /** EX-NOT-03, RG-NOT-5 : ABJ = épreuve de remplacement ou rattrapage ; ABI et NA = 0 ; FR = 0 + conseil de discipline. */
    public const ABSENCE_CODES = [
        'justified' => ['effect' => self::RETAKE, 'label' => 'Absence justifiée', 'aliases' => ['ABJ', 'ABS']],
        'unjustified' => ['effect' => self::ZERO, 'label' => 'Absence injustifiée', 'aliases' => ['ABI']],
        'na' => ['effect' => self::ZERO, 'label' => 'Non autorisé à composer', 'aliases' => ['NA']],
        'fr' => ['effect' => self::ZERO, 'label' => 'Fraude', 'aliases' => ['FR'], 'discipline' => true],
    ];

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const VALIDATED = 'validated';
    public const LOCKED = 'locked';
    public const PUBLISHED = 'published';

    /** EX-NOT-05 : brouillon → soumise → validée (chef de département) → verrouillée (2FA) → publiée (scolarité, après PV signé). */
    public const LIFECYCLE = [
        'states' => [self::DRAFT, self::SUBMITTED, self::VALIDATED, self::LOCKED, self::PUBLISHED],
        'editable' => [self::DRAFT],
        'published' => self::PUBLISHED,
        'transitions' => [
            ['from' => self::DRAFT, 'to' => self::SUBMITTED, 'permission' => 'higher.grades.submit', 'require_recent_2fa' => false],
            ['from' => self::SUBMITTED, 'to' => self::DRAFT, 'permission' => 'assessment.validate', 'require_recent_2fa' => false],
            ['from' => self::SUBMITTED, 'to' => self::VALIDATED, 'permission' => 'assessment.validate', 'require_recent_2fa' => false],
            ['from' => self::VALIDATED, 'to' => self::LOCKED, 'permission' => 'assessment.validate', 'require_recent_2fa' => true],
            ['from' => self::LOCKED, 'to' => self::PUBLISHED, 'permission' => 'higher.grades.publish', 'require_recent_2fa' => true],
        ],
    ];

    /** EX-NOT-06 : chef de département puis directeur d'école ; jamais le demandeur, jamais deux fois la même personne. */
    public const CORRECTION_CHAIN = [
        ['permission' => 'assessment.correction.approve', 'role' => 'higher_chef_dept', 'label' => 'Chef de département', 'require_recent_2fa' => false],
        ['permission' => 'assessment.correction.approve', 'role' => 'higher_dir_ecole', 'label' => 'Directeur d\'école', 'require_recent_2fa' => true],
    ];

    /** Séparation des tâches : qui a saisi une note de l'évaluation ne la verrouille pas. */
    public const SEGREGATE_LOCK = [self::LOCKED];

    /** EX-NOT-07 : délai de requête de l'étudiant après publication (jours). */
    public const QUERY_DAYS = 7;

    /** Poids par défaut (pour cent) : contrôle continu et TP 40, examen et rattrapage 60. */
    public const WEIGHTS = ['cc' => 40, 'tp' => 40, 'exam' => 60, 'retake' => 60];

    /** NOT-11 : relance tous les N jours après la date limite de saisie. */
    public const REMINDER_EVERY_DAYS = 3;

    /** Clés de paramètres (socle `assessment.*`, application `higher.grades.*`). */
    public const SETTINGS = [
        'assessment.step' => self::STEP,
        'assessment.absence_codes' => self::ABSENCE_CODES,
        'assessment.lifecycle' => self::LIFECYCLE,
        'assessment.correction.chain' => self::CORRECTION_CHAIN,
        'assessment.segregate_lock' => self::SEGREGATE_LOCK,
        'higher.grades.query_days' => self::QUERY_DAYS,
        'higher.grades.default_weights' => self::WEIGHTS,
        'higher.grades.reminder_every_days' => self::REMINDER_EVERY_DAYS,
    ];

    private function __construct()
    {
    }
}
