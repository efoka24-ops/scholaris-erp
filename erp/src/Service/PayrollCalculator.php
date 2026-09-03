<?php

declare(strict_types=1);

namespace Scholaris\Service;

/**
 * Calcul d'un bulletin de paie camerounais, cas courant.
 *
 * Les taux appliques :
 *
 *  - CNPS pension vieillesse : 4,2 % a la charge du salarie, 4,2 % a la
 *    charge de l'employeur, plafonnes a 750 000 FCFA de salaire mensuel ;
 *  - prestations familiales et risques professionnels : 7 % employeur, hors
 *    plafond ;
 *  - IRPP : bareme progressif annuel, applique au salaire net categoriel
 *    apres abattement de 30 % pour frais professionnels et de 500 000 FCFA.
 *
 * Ce calcul couvre la situation ordinaire d'un agent mensualise. Les cas
 * particuliers (heures supplementaires, primes imposables specifiques,
 * retenues sur decision de justice) se saisissent en lignes complementaires
 * sur le bulletin, ce que le module permet.
 *
 * Les taux sont ici des constantes plutot qu'un parametrage : les changer
 * suppose une decision de l'Etat, pas un reglage d'etablissement, et un taux
 * modifiable par ecran serait surtout un moyen de se tromper.
 */
final class PayrollCalculator
{
    private const CNPS_RATE_EMPLOYEE = 0.042;

    private const CNPS_RATE_EMPLOYER = 0.042;

    /** Prestations familiales et risques professionnels, employeur seul. */
    private const FAMILY_RATE_EMPLOYER = 0.07;

    private const CNPS_CEILING = 750000.0;

    /** Abattement forfaitaire pour frais professionnels. */
    private const PROFESSIONAL_ALLOWANCE = 0.30;

    /** Abattement annuel de droit commun. */
    private const ANNUAL_ALLOWANCE = 500000.0;

    /**
     * @return array{
     *     gross: float,
     *     cnps_employee: float,
     *     cnps_employer: float,
     *     income_tax: float,
     *     net: float,
     *     lines: list<array{label: string, kind: string, amount: float}>
     * }
     */
    public static function compute(float $baseSalary): array
    {
        $gross = max(0.0, round($baseSalary, 2));

        // Le plafond porte sur l'assiette, pas sur la cotisation : au-dela de
        // 750 000 FCFA, la cotisation cesse de croitre.
        $base = min($gross, self::CNPS_CEILING);

        $cnpsEmployee = round($base * self::CNPS_RATE_EMPLOYEE, 2);
        $cnpsEmployer = round($base * self::CNPS_RATE_EMPLOYER, 2)
            + round($gross * self::FAMILY_RATE_EMPLOYER, 2);

        $tax = self::incomeTax($gross, $cnpsEmployee);

        $net = round($gross - $cnpsEmployee - $tax, 2);

        return [
            'gross' => $gross,
            'cnps_employee' => $cnpsEmployee,
            'cnps_employer' => round($cnpsEmployer, 2),
            'income_tax' => $tax,
            'net' => $net,
            'lines' => [
                ['label' => 'Salaire de base', 'kind' => 'GAIN', 'amount' => $gross],
                ['label' => 'CNPS - pension vieillesse (4,2 %)', 'kind' => 'RETENUE', 'amount' => $cnpsEmployee],
                ['label' => 'IRPP', 'kind' => 'RETENUE', 'amount' => $tax],
            ],
        ];
    }

    /**
     * IRPP mensuel, obtenu en annualisant puis en redivisant.
     *
     * Le bareme est progressif et annuel : appliquer directement des tranches
     * mensuelles donnerait un resultat faux des que le salaire varie d'un
     * mois sur l'autre.
     */
    private static function incomeTax(float $monthlyGross, float $cnpsEmployee): float
    {
        $annualGross = ($monthlyGross - $cnpsEmployee) * 12;

        $taxable = $annualGross * (1 - self::PROFESSIONAL_ALLOWANCE) - self::ANNUAL_ALLOWANCE;

        if ($taxable <= 0) {
            return 0.0;
        }

        // Tranches annuelles : plafond de tranche, taux applicable.
        $brackets = [
            [2000000.0, 0.10],
            [3000000.0, 0.15],
            [5000000.0, 0.25],
            [PHP_FLOAT_MAX, 0.35],
        ];

        $tax = 0.0;
        $previous = 0.0;

        foreach ($brackets as [$ceiling, $rate]) {
            if ($taxable <= $previous) {
                break;
            }

            $slice = min($taxable, $ceiling) - $previous;
            $tax += $slice * $rate;
            $previous = $ceiling;
        }

        return round($tax / 12, 2);
    }

    /** Nom du mois, pour l'intitule d'une periode de paie. */
    public static function monthName(int $month): string
    {
        $names = [
            1 => 'Janvier', 2 => 'Fevrier', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Aout',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Decembre',
        ];

        return $names[$month] ?? 'Mois '.$month;
    }
}
