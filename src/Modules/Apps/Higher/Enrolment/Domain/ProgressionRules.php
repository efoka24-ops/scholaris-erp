<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Domain;

/**
 * Réinscription (RG-INS-3, RG-INS-4, RG-INS-5) : décisions de passage de l'année N et niveau autorisé en N+1.
 *  - admis, admis avec dettes : niveau suivant ; les UE manquantes de N (dettes) sont inscrites d'office ;
 *  - redouble : même niveau ; les UE acquises sont capitalisées (reportées, non repassées) ;
 *  - exclu (insuffisance) : aucune réinscription dans la filière sans dérogation du directeur d'école.
 * Le nombre d'inscriptions d'un même niveau est limité (défaut 2, `higher.enrolment.max_consecutive`), au-delà dérogation (R04).
 */
final class ProgressionRules
{
    public const ADMITTED = 'admitted';
    public const ADMITTED_WITH_DEBTS = 'admitted_with_debts';
    public const REPEAT = 'repeat';
    public const EXCLUDED = 'excluded';
    public const DECISIONS = [self::ADMITTED, self::ADMITTED_WITH_DEBTS, self::REPEAT, self::EXCLUDED];

    public const DEFAULT_MAX_CONSECUTIVE = 2;

    private function __construct()
    {
    }

    /**
     * Motif de refus de la réinscription vers le niveau de rang $targetOrdinal, null si permise.
     *
     * @param string|null $decision décision de l'année N dans la filière (null : aucune)
     */
    public static function refusal(?string $decision, int $previousOrdinal, int $targetOrdinal): ?string
    {
        if ($decision === null) {
            return 'Aucune décision de passage de l\'année précédente : la réinscription exige « admis » ou « admis avec dettes » (RG-INS-3), ou « redouble » pour le même niveau.';
        }
        if ($targetOrdinal > $previousOrdinal) {
            if ($targetOrdinal !== $previousOrdinal + 1) {
                return 'La réinscription ne peut viser que le niveau suivant.';
            }

            return in_array($decision, [self::ADMITTED, self::ADMITTED_WITH_DEBTS], true)
                ? null
                : 'Passage au niveau suivant refusé : la décision de l\'année N n\'est ni « admis » ni « admis avec dettes » (RG-INS-3).';
        }
        if ($targetOrdinal === $previousOrdinal) {
            return $decision === self::REPEAT ? null : 'Réinscription dans le même niveau seulement après une décision « redouble ».';
        }

        return 'Réinscription dans un niveau inférieur refusée.';
    }
}
