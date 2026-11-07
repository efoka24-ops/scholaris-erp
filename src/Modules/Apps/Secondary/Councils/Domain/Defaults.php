<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

/**
 * Valeurs d'EXEMPLE du CDC secondaire (jeu `example_cdc`, D12, RG-SEC-4) : NON officielles, « à confirmer » par chaque
 * établissement (Q2). Aucune n'est appliquée par le code : la stratégie ne lit que la version de règles publiée par
 * l'établissement (coefficients, seuils, barèmes et règles de passage paramétrés, versionnés ; une année figée n'est pas
 * recalculée). Ce jeu sert de point de départ proposé à la saisie et de données aux tests d'acceptation (TST-01 à TST-04, TST-10).
 *
 * Seule classe du dossier Domain où un nombre autre que 0 et 1 peut être écrit (tests/Architecture/SecondaryConventionsTest).
 */
final class Defaults
{
    public const SOURCE = 'example_cdc';
    public const STATUS = 'à confirmer';
    public const NOTICE = 'Valeurs d\'exemple du cahier des charges, non officielles : à remplacer par les textes du MINESEC en vigueur et le règlement intérieur de l\'établissement.';

    /** Options de la stratégie `secondary` (CDC, « Règles de calcul et notes »). */
    public const STRATEGY_OPTIONS = [
        'example' => self::SOURCE,
        'round_each_step' => true,
        // séquences → trimestre : Σ (moyenne × coefficient) ÷ Σ coefficients ; trimestres → année : moyenne des trimestres.
        'aggregation_modes' => [SecondaryStrategy::MODE_SUBJECTS, SecondaryStrategy::MODE_PERIODS],
        'subject_alert_below' => 10.0,
        'work_proposals' => [
            ['code' => 'congratulations', 'label' => 'Félicitations et tableau d\'honneur', 'kind' => WorkProposals::DISTINCTION, 'min' => 16.0],
            ['code' => 'encouragements', 'label' => 'Encouragements et tableau d\'honneur', 'kind' => WorkProposals::DISTINCTION, 'min' => 14.0],
            ['code' => 'honour_roll', 'label' => 'Tableau d\'honneur', 'kind' => WorkProposals::DISTINCTION, 'min' => 12.0],
            ['code' => 'work_blame', 'label' => 'Blâme pour le travail', 'kind' => WorkProposals::SANCTION, 'below' => 8.0],
            ['code' => 'work_warning', 'label' => 'Avertissement pour le travail', 'kind' => WorkProposals::SANCTION, 'below' => 10.0],
        ],
        'distinction_blocked_by_conduct' => true,
        'practical' => ['subject_kinds' => ['practical'], 'subject_codes' => [], 'min' => 8.0, 'conditional_allowed' => true],
        'promotion' => ['at_depth' => 2, 'admit_from' => 10.0, 'review_from' => 9.0, 'exclude_repeaters' => true],
    ];

    /** Libellés des décisions de passage (decision_labels d'une version de règles). */
    public const DECISION_LABELS = [
        PromotionPolicy::ADMITTED => ['label' => 'Admis en classe supérieure', 'passing' => true],
        PromotionPolicy::COUNCIL_REVIEW => ['label' => 'Cas examiné par le conseil de classe', 'passing' => false],
        PromotionPolicy::REPEAT => ['label' => 'Redoublement proposé', 'passing' => false],
        PromotionPolicy::EXCLUSION => ['label' => 'Exclusion pour insuffisance proposée', 'passing' => false],
        PromotionPolicy::PRACTICAL_REFUSED => ['label' => 'Admission refusée (pratique professionnelle)', 'passing' => false],
    ];

    /** Barème des heures d'absence injustifiées dans le trimestre (RG-PRE-1, exemple). */
    public const CONDUCT_SCALE = [
        'tiers' => [
            ['from_hours' => 10.0, 'code' => 'conduct_warning', 'label' => 'Avertissement de conduite', 'level' => 'surveillant_general'],
            ['from_hours' => 20.0, 'code' => 'conduct_blame', 'label' => 'Blâme de conduite', 'level' => 'surveillant_general'],
            ['from_hours' => 30.0, 'code' => 'temporary_exclusion', 'label' => 'Exclusion temporaire de 3 jours', 'level' => 'chef_etablissement', 'days' => 3],
            ['above_hours' => 30.0, 'code' => 'discipline_council', 'label' => 'Conseil de discipline', 'level' => 'conseil_discipline'],
        ],
        'repeat_offence_code' => 'discipline_council',
    ];

    /** Grille d'évaluation pratique en atelier (EX-ATL-05, exemple) : critères, base de la note, plafond sur manquement grave. */
    public const PRACTICAL_GRID = [
        'base' => 20.0,
        'cap_on_severe_breach' => 8.0,
        'criteria' => [
            ['code' => 'preparation', 'label' => 'Préparation du poste et lecture du schéma', 'points' => 4.0],
            ['code' => 'safety', 'label' => 'Respect des consignes de sécurité', 'points' => 4.0],
            ['code' => 'execution', 'label' => 'Exécution du montage', 'points' => 6.0],
            ['code' => 'quality', 'label' => 'Qualité et contrôle du résultat', 'points' => 4.0],
            ['code' => 'time', 'label' => 'Temps alloué respecté', 'points' => 2.0],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Définition complète d'une version de règles d'exemple (POST /calculation-rules ou paramètres assessment.*) : barème sur
     * 20, note de passage, arrondi au centième, stratégie `secondary` et ses options, libellés de décision.
     *
     * @return array<string, mixed>
     */
    public static function ruleDefinition(): array
    {
        $labels = [];
        foreach (self::DECISION_LABELS as $code => $decision) {
            $labels[$code] = $decision;
        }

        return [
            'scale' => 20.0,
            'pass_mark' => 10.0,
            'rounding' => 2,
            'strategy' => SecondaryStrategy::KEY,
            'strategy_options' => self::STRATEGY_OPTIONS,
            'decision_labels' => $labels,
        ];
    }
}
