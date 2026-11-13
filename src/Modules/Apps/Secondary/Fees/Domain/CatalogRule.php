<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Domain;

/**
 * Règle pure du catalogue des frais autorisés (RG-FIN-2) : un frais est permis s'il existe une ligne du catalogue en vigueur à la
 * date donnée, de même nature, de même code quand la ligne en fixe un, et dont le plafond (s'il existe) n'est pas dépassé.
 */
final class CatalogRule
{
    /**
     * @param list<array<string, mixed>> $entries lignes de se_fee_catalog (établissement et client)
     * @return array<string, mixed>|null ligne qui autorise le frais, ou null
     */
    public static function match(array $entries, string $kind, ?string $code, int $amount, string $day): ?array
    {
        foreach ($entries as $entry) {
            if (!self::inForce($entry, $day) || (string) $entry['fee_kind'] !== $kind) {
                continue;
            }
            $entryCode = $entry['fee_code'] ?? null;
            if (is_string($entryCode) && $entryCode !== '' && $entryCode !== $code) {
                continue;
            }
            $max = $entry['max_amount'] ?? null;
            if ($max !== null && $amount > (int) $max) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    public static function inForce(array $entry, string $day): bool
    {
        $to = $entry['valid_to'] ?? null;

        return (string) $entry['valid_from'] <= $day && ($to === null || $to === '' || $day <= (string) $to);
    }
}
