<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Moteur de calcul appelable par une application (S5, S6) : règles en vigueur pour une structure, calcul d'un apprenant
 * avec ces règles (ou des règles fournies, pour une simulation sans écriture), décision annuelle, rangs avec ex æquo.
 * Fourni par le module Assessment (S09).
 *
 * @phpstan-import-type Unit from RuleStrategy
 * @phpstan-import-type LearnerResult from RuleStrategy
 * @phpstan-import-type PeriodSummary from RuleStrategy
 * @phpstan-import-type YearDecision from RuleStrategy
 */
interface RuleCalculator
{
    /** Règles en vigueur : version de la structure (ou de son programme), sinon de l'année, sinon règles de base. */
    public function rulesFor(string $tenantId, string $establishmentId, ?string $academicYearId, ?string $structureId = null): CalculationRules;

    /**
     * @param list<Unit> $units
     * @return LearnerResult
     */
    public function computeLearner(CalculationRules $rules, array $units, string $session = 'normal'): array;

    /**
     * @param list<PeriodSummary> $periods
     * @param array<string, mixed> $context
     * @return YearDecision
     */
    public function decideYear(CalculationRules $rules, array $periods, array $context = []): array;

    /**
     * @param array<string, float|null> $averages identifiant => moyenne
     * @return array<string, int|null> rangs, ex æquo au même rang (1, 2, 2, 4)
     */
    public function ranks(array $averages): array;
}
