<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Domain;

use InvalidArgumentException;

/**
 * Échéancier de la pension (EX-FIN-02) : tranches datées et pondérées. Par défaut 3 tranches égales (15 octobre, 15 janvier,
 * 15 avril, paramètre `higher.billing.default_terms`) ; un échéancier individuel négocié remplace celui de la grille.
 * Les montants sont entiers (XAF) : le reliquat de la division revient à la dernière tranche.
 */
final class PaymentTerms
{
    public const MAX_TERMS = 12;

    /** Échéancier par défaut : [mois-jour, poids] ; l'année de la tranche suit l'année académique (octobre à septembre). */
    public const DEFAULT = [['10-15', 1], ['01-15', 1], ['04-15', 1]];

    /** @param list<array{due_on: string, weight: int}> $terms */
    private function __construct(public readonly array $terms)
    {
    }

    /**
     * @param mixed $raw liste de {due_on: AAAA-MM-JJ, weight: entier ≥ 1}
     * @throws InvalidArgumentException
     */
    public static function fromArray(mixed $raw): self
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw) || count($raw) > self::MAX_TERMS) {
            throw new InvalidArgumentException('Échéancier : 1 à 12 tranches attendues.');
        }
        $terms = [];
        $previous = '';
        foreach ($raw as $term) {
            $due = is_array($term) ? ($term['due_on'] ?? null) : null;
            $weight = is_array($term) ? ($term['weight'] ?? 1) : null;
            if (!is_string($due) || !self::isDate($due) || !is_int($weight) || $weight < 1 || $weight > 1000) {
                throw new InvalidArgumentException('Chaque tranche a une date limite AAAA-MM-JJ et un poids entier de 1 à 1000.');
            }
            if ($due <= $previous) {
                throw new InvalidArgumentException('Les dates limites des tranches doivent être croissantes.');
            }
            $previous = $due;
            $terms[] = ['due_on' => $due, 'weight' => $weight];
        }

        return new self($terms);
    }

    /**
     * Échéancier par défaut pour l'année académique qui commence en `$startYear` (septembre/octobre de cette année).
     *
     * @param list<array{0: string, 1: int}> $model mois-jour et poids
     */
    public static function defaultFor(int $startYear, array $model = self::DEFAULT): self
    {
        $raw = [];
        foreach ($model as [$monthDay, $weight]) {
            $month = (int) substr($monthDay, 0, 2);
            $raw[] = ['due_on' => sprintf('%04d-%s', $month >= 8 ? $startYear : $startYear + 1, $monthDay), 'weight' => $weight];
        }

        return self::fromArray($raw);
    }

    /** @return list<int> montant de chaque tranche ; la somme vaut exactement $total */
    public function split(int $total): array
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Montant négatif.');
        }
        $weights = array_column($this->terms, 'weight');
        $sum = array_sum($weights);
        $last = array_pop($weights);
        $amounts = [];
        $given = 0;
        foreach ($weights as $weight) {
            $part = intdiv($total * $weight, $sum);
            $amounts[] = $part;
            $given += $part;
        }
        if ($last !== null) {
            $amounts[] = $total - $given;
        }

        return $amounts;
    }

    public function firstDueOn(): string
    {
        return $this->terms[0]['due_on'];
    }

    /** @return list<array{due_on: string, weight: int}> */
    public function toArray(): array
    {
        return $this->terms;
    }

    public static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
