<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

/**
 * Compare une identité saisie à une fiche existante sur trois critères : nom, date de naissance, téléphone.
 * Il y a doublon probable quand le nom concorde avec au moins un autre critère (« 2 critères sur 3 » au minimum).
 */
final class DuplicateMatcher
{
    public const TOTAL = 3;

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @param list<string> $extra critères supplémentaires déjà jugés concordants par une application (aucun par défaut)
     * @return list<string> critères concordants parmi name, birth_date, phone (puis `extra`) ; vide s'il n'y a pas de doublon probable
     */
    public static function criteria(array $input, array $existing, array $extra = []): array
    {
        $matched = [];
        if (Names::key((string) ($input['first_name'] ?? ''), (string) ($input['last_name'] ?? ''))
            === Names::key((string) ($existing['first_name'] ?? ''), (string) ($existing['last_name'] ?? ''))) {
            $matched[] = 'name';
        }
        $birth = $input['birth_date'] ?? null;
        if (is_string($birth) && $birth !== '' && $birth === ($existing['birth_date'] ?? null)) {
            $matched[] = 'birth_date';
        }
        $phone = Names::phoneKey(isset($input['phone']) ? (string) $input['phone'] : null);
        if ($phone !== null && $phone === Names::phoneKey(isset($existing['phone']) ? (string) $existing['phone'] : null)) {
            $matched[] = 'phone';
        }
        foreach ($extra as $criterion) {
            if (!in_array($criterion, $matched, true)) {
                $matched[] = $criterion;
            }
        }

        return in_array('name', $matched, true) && count($matched) >= 2 ? $matched : [];
    }
}
