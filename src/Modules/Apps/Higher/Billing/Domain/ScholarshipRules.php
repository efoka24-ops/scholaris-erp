<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Domain;

use InvalidArgumentException;

/**
 * Bourses et réductions (EX-FIN-03, RG-FIN-1) : mérite, personnel, fratrie dans le groupe, partenariat entreprise.
 * Remises sur la pension seulement (sauf paramètre `higher.billing.discount_targets`), cumul plafonné (`billing.discount_cap_percent`,
 * défaut 50 % : decisions.md R8), réparties par le socle sur les tranches restantes. Toute bourse attend la validation d'un
 * comptable autre que le demandeur.
 */
final class ScholarshipRules
{
    public const KINDS = ['merit', 'staff', 'sibling', 'partnership'];

    /** Type de remise du socle utilisé pour chaque bourse (le socle ne connaît pas « partenariat » : mérite, motif explicite). */
    public const SOCLE_KIND = ['merit' => 'merit', 'staff' => 'staff', 'sibling' => 'sibling', 'partnership' => 'merit'];

    public const DEFAULT_TARGETS = ['tuition'];
    public const DEFAULT_CAP_PERCENT = 50;
    /** Taux par défaut (decisions.md R24) : mérite 20 % (exemple du CDC), autres à fixer par l'institut. */
    public const DEFAULT_RATES = ['merit' => 20];

    private function __construct()
    {
    }

    public static function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Type de bourse : merit, staff, sibling ou partnership.');
        }
    }

    /** Montant estimé d'une remise sur une base (pourcentage arrondi à l'entier inférieur, ou montant fixe plafonné). */
    public static function amount(string $mode, int $value, int $base): int
    {
        return $mode === 'percent' ? intdiv($base * $value, 100) : min($value, $base);
    }

    /** Vrai si le cumul (remises déjà accordées ou en attente + nouvelle) dépasse le plafond en % de la base. */
    public static function exceedsCap(int $cumulated, int $base, int $capPercent): bool
    {
        return $cumulated * 100 > $base * $capPercent;
    }
}
