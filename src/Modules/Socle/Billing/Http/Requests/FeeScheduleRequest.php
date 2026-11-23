<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

use DateTimeImmutable;
use InvalidArgumentException;

/** Ligne de grille : un frais, un périmètre (classe, niveau, filière) et ses tranches dont la somme égale le montant. */
final class FeeScheduleRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'fee_item_id' => Field::id()->required(),
            'academic_year_id' => Field::id()->required(),
            'structure_id' => Field::id(),
            'level' => Field::string(60),
            'track' => Field::string(60),
            'amount_xaf' => Field::int(0, 100000000)->required(),
            'terms' => Field::custom(static function (mixed $value): array {
                if (!is_array($value) || $value === [] || !array_is_list($value) || count($value) > 24) {
                    throw new InvalidArgumentException('Indiquez de 1 à 24 tranches : [{"due_on": "AAAA-MM-JJ", "amount": 120000}].');
                }
                $terms = [];
                foreach ($value as $term) {
                    $date = is_array($term) && is_string($term['due_on'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $term['due_on']) : false;
                    if ($date === false || $date->format('Y-m-d') !== $term['due_on'] || !is_int($term['amount'] ?? null) || $term['amount'] <= 0) {
                        throw new InvalidArgumentException('Chaque tranche a une date AAAA-MM-JJ et un montant entier positif en XAF.');
                    }
                    $terms[] = ['due_on' => $term['due_on'], 'amount' => $term['amount']];
                }

                return $terms;
            })->required(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        $total = 0;
        foreach ((array) $values['terms'] as $term) {
            $total += (int) $term['amount'];
        }
        if ($total !== $values['amount_xaf']) {
            $errors['terms'] = [sprintf('La somme des tranches (%d) doit égaler le montant (%d).', $total, (int) $values['amount_xaf'])];
        }

        return $errors;
    }
}
