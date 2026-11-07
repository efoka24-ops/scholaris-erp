<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

/**
 * Distinctions et sanctions du travail proposées au conseil de classe (EX-BUL-05) : tranches de moyenne générale lues dans
 * l'option `work_proposals` de la version de règles, première tranche qui correspond retenue (`min` : moyenne au moins égale ;
 * `below` : moyenne strictement inférieure). Option `distinction_blocked_by_conduct` : une distinction n'est pas proposée à un
 * élève sanctionné pour la conduite dans la période (le conseil le sait, la stratégie de calcul non). Aucune tranche = aucune
 * proposition. Fonctions pures.
 */
final class WorkProposals
{
    public const OPTION = 'work_proposals';
    public const OPTION_BLOCKED = 'distinction_blocked_by_conduct';
    public const DISTINCTION = 'distinction';
    public const SANCTION = 'sanction';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options options de la stratégie
     * @return array{code: string, label: string, kind: string, blocked_by_conduct: bool}|null
     */
    public static function forAverage(array $options, ?float $average, bool $conductSanctioned = false): ?array
    {
        if ($average === null) {
            return null;
        }
        $tiers = $options[self::OPTION] ?? [];
        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            if (!is_array($tier) || !self::matches($tier, $average)) {
                continue;
            }
            $kind = (string) ($tier['kind'] ?? self::SANCTION);
            $blocked = $kind === self::DISTINCTION && $conductSanctioned && ($options[self::OPTION_BLOCKED] ?? false) === true;

            return $blocked ? null : ['code' => (string) $tier['code'], 'label' => (string) ($tier['label'] ?? $tier['code']), 'kind' => $kind, 'blocked_by_conduct' => false];
        }

        return null;
    }

    /**
     * Erreurs de l'option (vide si acceptable).
     *
     * @return list<string>
     */
    public static function errors(mixed $tiers): array
    {
        if (!is_array($tiers) || !array_is_list($tiers)) {
            return ['« work_proposals » est une liste de tranches {code, label, kind, min ou below}.'];
        }
        $errors = [];
        foreach ($tiers as $index => $tier) {
            $where = 'tranche ' . ($index + 1) . ' de « work_proposals »';
            if (!is_array($tier) || !is_string($tier['code'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $tier['code']) !== 1) {
                $errors[] = $where . ' : code attendu (minuscules, chiffres, « _ »).';

                continue;
            }
            if (!in_array($tier['kind'] ?? self::SANCTION, [self::DISTINCTION, self::SANCTION], true)) {
                $errors[] = $where . ' : kind vaut « distinction » ou « sanction ».';
            }
            $bounds = array_intersect_key($tier, ['min' => true, 'below' => true]);
            if (count($bounds) !== 1 || !self::isNumber(reset($bounds))) {
                $errors[] = $where . ' : une seule borne numérique, « min » ou « below ».';
            }
        }

        return $errors;
    }

    /** @param array<mixed> $tier */
    private static function matches(array $tier, float $average): bool
    {
        if (self::isNumber($tier['min'] ?? null)) {
            return $average >= (float) $tier['min'];
        }
        if (self::isNumber($tier['below'] ?? null)) {
            return $average < (float) $tier['below'];
        }

        return false;
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
