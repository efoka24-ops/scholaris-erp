<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

use InvalidArgumentException;

/**
 * Barème des heures d'absence injustifiées (RG-PRE-1, paramètre `secondary.discipline.scale` du règlement intérieur) :
 * paliers `from_hours` (au moins) ou `above_hours` (strictement plus), dans l'ordre croissant ; le DERNIER palier atteint est
 * retenu. Récidive : palier `repeat_offence_code` s'il est fourni. Le résultat est une PROPOSITION (SanctionProposal), jamais
 * une sanction appliquée (RG-PRE-2). Barème vide : aucune proposition. Fonctions pures.
 */
final class ConductScale
{
    /**
     * @param array{tiers?: list<array<string, mixed>>, repeat_offence_code?: string} $scale
     */
    public function __construct(private readonly array $scale)
    {
        foreach ($scale['tiers'] ?? [] as $index => $tier) {
            $bound = $tier['from_hours'] ?? $tier['above_hours'] ?? null;
            if (!is_string($tier['code'] ?? null) || (!is_int($bound) && !is_float($bound))) {
                throw new InvalidArgumentException('Palier ' . ($index + 1) . ' du barème : code et « from_hours » ou « above_hours » obligatoires.');
            }
        }
    }

    public function propose(string $personId, float $unjustifiedHours, bool $repeatOffence = false): ?SanctionProposal
    {
        $retained = null;
        foreach ($this->scale['tiers'] ?? [] as $tier) {
            if (self::reached($tier, $unjustifiedHours)) {
                $retained = $tier;
            }
        }
        $repeatCode = $this->scale['repeat_offence_code'] ?? null;
        if ($repeatOffence && is_string($repeatCode)) {
            foreach ($this->scale['tiers'] ?? [] as $tier) {
                if ($tier['code'] === $repeatCode) {
                    $retained = $tier;
                }
            }
        }
        if ($retained === null) {
            return null;
        }

        return SanctionProposal::propose($personId, (string) $retained['code'], (string) ($retained['label'] ?? $retained['code']), (string) ($retained['level'] ?? ''), $unjustifiedHours);
    }

    /** @param array<string, mixed> $tier */
    private static function reached(array $tier, float $hours): bool
    {
        if (isset($tier['above_hours'])) {
            return $hours > (float) $tier['above_hours'];
        }

        return $hours >= (float) $tier['from_hours'];
    }
}
