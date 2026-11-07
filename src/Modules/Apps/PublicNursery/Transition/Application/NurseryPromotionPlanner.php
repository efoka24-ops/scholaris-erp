<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Transition\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryWriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\ConditionalPromotionPlanner;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

final class NurseryPromotionPlanner implements ConditionalPromotionPlanner
{
    public const CONTAINER_KEY = 'organisation.closure.promotion.public_nursery';
    private const AUTO_PROMOTE = 'public_nursery.transition.auto_promote';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly OrgUnitTree $tree,
        private readonly SettingsReader $settings,
        private readonly TransitionService $transitions,
        private readonly NurseryWriteGate $gate,
    ) {
    }

    public function establishmentTypes(): array
    {
        return ['nursery'];
    }

    public function appliesTo(string $tenantId, string $establishmentId): bool
    {
        return $this->gate->allowsReads($tenantId);
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        if (!$this->gate->allowsWrites($tenantId)) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Application Maternelle publique suspendue ou fermée : aucune décision de passage créée.');
        }
        if ($this->settings->value($tenantId, $establishmentId, self::AUTO_PROMOTE, true) !== true) {
            return new ClosureStepResult(ClosureStepResult::SKIPPED, 'Passage automatique désactivé pour cette maternelle publique.');
        }
        $yearRow = $this->connections->pdo()->prepare('SELECT label FROM academic_years WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
        $yearRow->execute([$tenantId, $establishmentId, $fromYearId]);
        $label = $yearRow->fetchColumn();
        if ($label === false) {
            throw HttpException::notFound('Année scolaire introuvable.');
        }
        $year = ['label' => (string) $label];
        $rows = $this->connections->pdo()->prepare(
            "SELECT person_id, structure_id FROM enrollments WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND status IN ('active', 'conditional') AND structure_id IS NOT NULL ORDER BY id",
        );
        $rows->execute([$tenantId, $establishmentId, $fromYearId]);

        $counts = ['proposed' => 0, 'unknown_level' => 0];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $level = $this->fromStructure($tenantId, $establishmentId, (string) $row['structure_id']);
            if ($level === null) {
                ++$counts['unknown_level'];
                continue;
            }
            $this->transitions->propose($tenantId, $establishmentId, (string) $row['person_id'], (string) $year['label'], $level);
            ++$counts['proposed'];
        }

        $summary = sprintf('%d proposition(s) de passage créées ou mises à jour.', $counts['proposed']);
        if ($counts['unknown_level'] > 0) {
            $summary .= ' ' . sprintf('%d structure(s) sans niveau PS/MS/GS reconnu.', $counts['unknown_level']);
        }

        return new ClosureStepResult(ClosureStepResult::DONE, $summary, $counts);
    }

    private function fromStructure(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        $levelId = $this->tree->levelOf($tenantId, $establishmentId, $structureId) ?? $structureId;
        $structure = $this->tree->structure($tenantId, $establishmentId, $levelId);
        if ($structure === null) {
            return null;
        }

        return self::normalizeLevel($structure->name);
    }

    private static function normalizeLevel(string $name): ?string
    {
        $value = mb_strtolower(trim($name));
        return match (true) {
            preg_match('/(^|\b)ps(\b|$)|petite\s+section/u', $value) === 1 => 'ps',
            preg_match('/(^|\b)ms(\b|$)|moyenne\s+section/u', $value) === 1 => 'ms',
            preg_match('/(^|\b)gs(\b|$)|grande\s+section/u', $value) === 1 => 'gs',
            default => null,
        };
    }
}
