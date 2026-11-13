<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Domain;

/**
 * Valeurs de départ des frais du secondaire, TOUTES « à confirmer » (Q4, Q11, CDC EX-FIN-01 à 06) : aucune n'est une règle
 * officielle. Elles ne servent qu'à amorcer des paramètres modifiables par l'établissement (SettingsWriter::presetIfAbsent, jamais
 * d'écrasement) ou à donner une valeur quand le paramètre est absent.
 */
final class FeesDefaults
{
    public const CONFIRMATION = 'to_confirm';

    /**
     * Natures de frais ajoutées à celles du socle (registration, tuition, other) sous `billing.fee_kinds.secondary` : APEE,
     * contributions exigibles (public, suivies par cartescolaire.cm), atelier, laboratoire, tenue, assurance, services annexes.
     * Les frais d'examen officiels n'y figurent pas (RG-FIN-3).
     */
    public const KINDS = ['apee', 'contribution', 'workshop_fee', 'lab_fee', 'uniform', 'insurance', 'medical', 'boarding', 'canteen', 'transport'];

    /** Natures que l'établissement ne facture jamais (frais d'examen payés sur cartescolaire.cm, RG-FIN-3) : paramètre `secondary.fees.refused_kinds`. */
    public const REFUSED_KINDS = ['exam_fee'];

    /**
     * Préréglages posés à l'ouverture des frais du privé [fees_private] (T830, RG-FIN-4, Q11) : affectation par nature (inscription,
     * APEE, pension, puis le reste), enfants dans l'ordre d'inscription, relances graduées J-7, J+1, J+15 (EX-FIN-05) envoyées avec
     * les modèles du secondaire NOT-07 et NOT-08 (`secondary.fees.notice_offsets`, FeeNotices) : la relance générique du socle est
     * alors coupée (`billing.reminder_offsets` vide) pour ne jamais envoyer deux SMS pour la même tranche. Un établissement qui garde
     * des relances du socle (liste non vide ou absente) n'en reçoit pas du secondaire.
     *
     * @var array<string, mixed>
     */
    public const PRIVATE_PRESETS = [
        'billing.allocation_rule' => 'kind_priority',
        'billing.allocation_kind_order' => ['registration', 'apee', 'tuition', '*'],
        'billing.allocation_sibling_order' => 'enrolled_first',
        'billing.reminder_offsets' => [],
        'secondary.fees.notice_offsets' => self::NOTICE_OFFSETS,
    ];

    /** Décalages des relances NOT-07 (négatif ou nul : avant l'échéance) et NOT-08 (positif : tranche en retard), en jours. */
    public const NOTICE_OFFSETS = [-7, 1, 15];

    /** NOT-10 : nombre de jours avant la date limite d'inscription à l'examen (`secondary.fees.exam_fee_notice_days`). */
    public const EXAM_FEE_NOTICE_DAYS = 15;

    /** Borne des décalages acceptés dans les paramètres de relance (jours). */
    public const MAX_NOTICE_OFFSET = 365;

    /** Jours rattrapés si la tâche planifiée n'a pas tourné (une relance n'est jamais envoyée deux fois). */
    public const NOTICE_CATCH_UP_DAYS = 2;

    /**
     * Colonnes du fichier des conseils de fin d'année pour cartescolaire.cm (DOC-33, API-04), paramètre
     * `secondary.carte_scolaire.columns.year_end` (Q1, « à confirmer ») ; séparateur `;`, UTF-8.
     */
    public const YEAR_END_COLUMNS = ['matricule', 'nom', 'prenoms', 'date_naissance', 'classe', 'moyenne_annuelle', 'rang', 'decision'];

    /** Types de documents dont la remise est retardée en cas d'impayé [fees_private] (`secondary.fees.blocked_document_types`) : aucun par défaut. */
    public const BLOCKED_DOCUMENT_TYPES = [];

    /** Bornes d'un plan de paiement individuel (nombre d'échéances) : paramètre `secondary.fees.plan_max_installments`. */
    public const PLAN_MAX_INSTALLMENTS = 12;

    private function __construct()
    {
    }
}
