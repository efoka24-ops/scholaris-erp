<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;

/**
 * Brouillon de version de règles d'un niveau ou d'une classe (T900) : règles en vigueur (ou jeu d'exemple `example_cdc` s'il n'y en a
 * pas encore de la stratégie `secondary`) dont l'option `coefficients` est REMPLIE depuis les coefficients versionnés de Setup
 * (CoefficientBook : niveau, série). Rien n'est publié ici : l'écran publie le brouillon relu par POST /calculation-rules (socle),
 * sur la structure du niveau (portée par défaut) ou de la classe (série propre).
 */
final class RuleDraftService
{
    public const READ = ['secondary.council.prepare', 'secondary.council.preside', 'secondary.settings.manage'];

    public function __construct(
        private readonly Access $access,
        private readonly ClassProfiles $classes,
        private readonly CoefficientBook $coefficients,
        private readonly RuleCalculator $calculator,
    ) {
    }

    /** @return array<string, mixed> */
    public function draft(Principal $principal, string $establishmentId, string $classId, string $scope): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $tenantId = $principal->tenantId;
        $class = $this->classes->find($tenantId, $establishmentId, $classId) ?? throw HttpException::notFound('Classe inconnue du secondaire.');
        $level = $this->classes->level($tenantId, $establishmentId, $class->levelId);
        $levelStructure = is_array($level) && is_string($level['structure_id'] ?? null) && $level['structure_id'] !== '' ? $level['structure_id'] : null;
        if ($scope === 'level' && $levelStructure === null) {
            throw HttpException::conflict('Le niveau de cette classe n\'a pas de structure : publiez les règles sur la classe.');
        }

        $current = $this->calculator->rulesFor($tenantId, $establishmentId, $class->academicYearId, $classId);
        $fromExample = $current->strategy !== SecondaryStrategy::KEY;
        $definition = Defaults::ruleDefinition();
        if (!$fromExample) {
            $labels = [];
            foreach ($current->decisionLabels as $code => $label) {
                $labels[$code] = ['label' => $label, 'passing' => in_array($code, $current->passingDecisions, true)];
            }
            $definition = ['scale' => $current->scale, 'pass_mark' => $current->passMark, 'rounding' => $current->rounding, 'strategy' => SecondaryStrategy::KEY,
                'strategy_options' => $current->options, 'decision_labels' => $labels === [] ? $definition['decision_labels'] : $labels] + $definition;
        }
        $book = [];
        $lines = [];
        foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $class->academicYearId, $class->levelCode, $scope === 'class' ? $class->streamId : null) as $line) {
            $book[$line->subjectId] = ['coefficient' => $line->coefficient, 'counts_in_average' => $line->countsInAverage, 'role' => $line->role];
            $lines[] = ['subject_id' => $line->subjectId, 'code' => $line->code, 'label' => $line->label, 'coefficient' => $line->coefficient, 'role' => $line->role,
                'counts_in_average' => $line->countsInAverage, 'version_no' => $line->versionNo, 'origin' => $line->origin];
        }
        if ($book === []) {
            throw HttpException::conflict('Aucun coefficient en vigueur pour ce niveau dans Setup : saisissez les coefficients avant de publier les règles.');
        }
        $options = is_array($definition['strategy_options'] ?? null) ? $definition['strategy_options'] : [];
        $options[SecondaryStrategy::OPTION_COEFFICIENTS] = $book;
        $definition['strategy_options'] = $options;

        return [
            'class_id' => $classId, 'academic_year_id' => $class->academicYearId, 'level_code' => $class->levelCode, 'stream_id' => $scope === 'class' ? $class->streamId : null,
            'scope' => $scope, 'structure_id' => $scope === 'level' ? $levelStructure : $classId,
            'label' => 'Règles ' . ($scope === 'level' ? $class->levelCode : $class->label) . ' (coefficients Setup)',
            'from_example' => $fromExample, 'example_notice' => $fromExample ? Defaults::NOTICE : null,
            'coefficients' => $lines, 'definition' => $definition,
        ];
    }
}
