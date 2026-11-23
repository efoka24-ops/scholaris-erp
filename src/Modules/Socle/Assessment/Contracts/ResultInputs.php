<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Données d'une ResultInputSource : unités par apprenant (format des stratégies), année, règles à appliquer (null : règles
 * publiées au socle pour la structure) et options propres à un apprenant, ajoutées aux options de la stratégie pour son seul
 * calcul (ex. points de jury accordés).
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class ResultInputs
{
    /**
     * @param array<string, list<Unit>> $units personne => unités
     * @param array<string, array<string, mixed>> $learnerOptions personne => options
     */
    public function __construct(
        public readonly array $units,
        public readonly ?string $academicYearId = null,
        public readonly ?CalculationRules $rules = null,
        public readonly array $learnerOptions = [],
    ) {
    }
}
