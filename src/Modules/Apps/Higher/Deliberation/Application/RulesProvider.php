<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherBtsHndStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LetterGrid;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\Rules;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;

/**
 * Règles de calcul d'un jury : la version publiée au socle pour le groupe (ou sa filière, S5) si elle nomme une stratégie de
 * l'IPES ; sinon des règles dérivées du règlement des études de l'école, du régime et de l'année (Academic, RG-ACA-3), avec les
 * valeurs par défaut du CDC. Fournit aussi le modèle de version à publier au socle (POST /calculation-rules).
 */
final class RulesProvider
{
    public const LABELS = [
        'admitted' => 'Admis', 'compensated' => 'Admis par compensation', 'resit' => 'Rattrapage', 'adjourned' => 'Ajourné',
        'admitted_with_debts' => 'Admis avec dettes', 'repeat' => 'Redouble', 'excluded' => 'Exclu pour insuffisance',
    ];
    public const PASSING = ['admitted', 'compensated', 'admitted_with_debts'];

    public function __construct(
        private readonly RuleCalculator $calculator,
        private readonly RegulationBook $regulations,
    ) {
    }

    public static function strategyFor(string $regime): string
    {
        return in_array($regime, ['bts', 'hnd'], true) ? HigherBtsHndStrategy::KEY : HigherLmdStrategy::KEY;
    }

    /** @param array<string, mixed> $jury ligne hi_juries */
    public function forJury(array $jury): CalculationRules
    {
        $tenantId = (string) $jury['tenant_id'];
        $establishmentId = (string) $jury['establishment_id'];
        $published = $this->calculator->rulesFor($tenantId, $establishmentId, (string) $jury['academic_year_id'], (string) $jury['structure_id']);
        if (in_array($published->strategy, [HigherLmdStrategy::KEY, HigherBtsHndStrategy::KEY], true)) {
            return $published;
        }

        return $this->fromRegulation($tenantId, $establishmentId, (string) $jury['school_id'], (string) $jury['regime'], (string) $jury['academic_year_id']);
    }

    public function fromRegulation(string $tenantId, string $establishmentId, string $schoolId, string $regime, string $academicYearId): CalculationRules
    {
        $parameters = $this->regulations->effective($tenantId, $establishmentId, $schoolId, $regime, $academicYearId)->parameters ?? [];
        $template = $this->template($regime, $parameters);

        return new CalculationRules(
            0,
            $template['strategy'],
            20.0,
            10.0,
            (int) ($parameters['rounding_decimals'] ?? 2),
            Rules::MENTIONS,
            10.0,
            10.0,
            10.0,
            $template['strategy_options'],
            self::LABELS,
            self::PASSING,
            [],
        );
    }

    /**
     * Version de règles à publier au socle pour une filière : stratégie, options issues du règlement, étiquettes de décision.
     *
     * @param array<string, bool|float|int|null> $parameters paramètres du règlement des études
     * @return array{strategy: string, strategy_options: array<string, mixed>, decision_labels: array<string, mixed>, mentions: list<array{min: float, label: string}>}
     */
    public function template(string $regime, array $parameters): array
    {
        $options = array_filter($parameters, static fn ($v): bool => $v !== null);
        $options += ['cc_weight_percent' => 40, 'jury_points_max' => 0.5, 'final_credits' => 180];
        if (self::strategyFor($regime) === HigherLmdStrategy::KEY) {
            $options += ['eliminatory_unit_mark' => 7, 'unit_pass_mark' => 10, 'compensation_threshold' => 10, 'letter_grid' => LetterGrid::DEFAULT];
        }
        $labels = [];
        foreach (self::LABELS as $code => $label) {
            $labels[$code] = in_array($code, self::PASSING, true) ? ['label' => $label, 'passing' => true] : $label;
        }

        return ['strategy' => self::strategyFor($regime), 'strategy_options' => $options, 'decision_labels' => $labels, 'mentions' => Rules::MENTIONS];
    }

    /**
     * @param array<string, mixed> $jury
     * @return array<string, bool|float|int|null>
     */
    public function parameters(array $jury): array
    {
        return $this->regulations->effective((string) $jury['tenant_id'], (string) $jury['establishment_id'], (string) $jury['school_id'], (string) $jury['regime'], (string) $jury['academic_year_id'])->parameters ?? [];
    }
}
