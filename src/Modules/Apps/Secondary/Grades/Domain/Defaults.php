<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Domain;

/**
 * Préréglages d'EXEMPLE des notes du secondaire (T868, D12 : jeu `example_cdc`, « à confirmer ») posés à la demande de
 * l'établissement par POST /secondary/grades/settings/preset, JAMAIS par-dessus une valeur déjà posée (SettingsWriter). Ce sont des
 * paramètres du socle Assessment (`assessment.*`) et du secondaire (`secondary.grades.*`) : l'établissement les modifie ensuite par
 * l'écran des paramètres. Aucune de ces valeurs n'est appliquée d'office.
 *
 * Cycle (EX-NOT-05) : brouillon → soumise (enseignant) → validée (censeur) → verrouillée (censeur, deuxième authentification
 * récente) → publiée. « Non évaluée » (RG-BUL-1) est posé par le censeur. Seul l'état verrouillé (ou publié, ou non évalué) fige la
 * note : le socle refuse la validation du conseil tant qu'une épreuve reste dans un état modifiable (TST-05).
 */
final class Defaults
{
    public const ORIGIN = 'example_cdc';

    public const LIFECYCLE = [
        'states' => ['draft', 'submitted', 'validated', 'locked', 'published', 'not_evaluated'],
        'editable' => ['draft', 'submitted', 'validated'],
        'published' => 'published',
        'transitions' => [
            ['from' => 'draft', 'to' => 'submitted', 'permission' => 'secondary.grades.enter', 'require_recent_2fa' => false],
            ['from' => 'submitted', 'to' => 'draft', 'permission' => 'secondary.grades.validate', 'require_recent_2fa' => false],
            ['from' => 'submitted', 'to' => 'validated', 'permission' => 'secondary.grades.validate', 'require_recent_2fa' => false],
            ['from' => 'validated', 'to' => 'locked', 'permission' => 'secondary.grades.lock', 'require_recent_2fa' => true],
            ['from' => 'locked', 'to' => 'published', 'permission' => 'secondary.grades.lock', 'require_recent_2fa' => false],
            ['from' => 'draft', 'to' => 'not_evaluated', 'permission' => 'secondary.grades.validate', 'require_recent_2fa' => false],
            ['from' => 'not_evaluated', 'to' => 'draft', 'permission' => 'secondary.grades.validate', 'require_recent_2fa' => false],
        ],
    ];

    /** RG-NOT-2, RG-NOT-3, EX-NOT-03 : fraude = zéro, dispensé = hors moyenne, remplacement = épreuve de rattrapage. */
    public const ABSENCE_CODES = [
        'fraud' => ['effect' => 'zero', 'label' => 'Fraude', 'aliases' => ['FR']],
        'exempt' => ['effect' => 'excluded', 'label' => 'Dispensé', 'aliases' => ['DI']],
        'replacement' => ['effect' => 'retake', 'label' => 'Épreuve de remplacement', 'aliases' => ['RE']],
    ];

    /** RG-NOT-1 : quart de point (exemple du CDC). */
    public const STEP = 0.25;

    /** EX-NOT-06 : correction d'une note verrouillée validée par le censeur PUIS par le chef d'établissement. */
    public const CORRECTION_CHAIN = [
        ['permission' => 'secondary.grades.validate', 'label' => 'Censeur', 'require_recent_2fa' => false],
        ['permission' => 'secondary.reportcard.validate', 'label' => 'Chef d\'établissement', 'require_recent_2fa' => true],
    ];

    /**
     * Paramètres posés par le préréglage, dans l'ordre d'affichage.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'assessment.lifecycle' => self::LIFECYCLE,
            'assessment.segregate_lock' => true,
            'assessment.absence_codes' => self::ABSENCE_CODES,
            'assessment.step' => self::STEP,
            'assessment.correction.chain' => self::CORRECTION_CHAIN,
            'assessment.subject_ranks' => true,
            // Saisie hors ligne : désactivée tant que l'établissement ne l'active pas (NF-S4).
            'secondary.grades.offline_entry' => false,
            // Q7 : correspondance note et lettre des bulletins anglophones désactivée (table vide).
            'secondary.grades.letter_scale' => [],
        ];
    }
}
