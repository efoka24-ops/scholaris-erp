<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodTree;

/**
 * Cadre temporel d'un tableau de bord, par les contrats d'Organisation (PeriodTree, CurrentPeriod) :
 *  - année : `academic_year_id` demandé, sinon celle de la période en cours, sinon 422 ;
 *  - trimestre : `period_id` demandé (trimestre ou semestre de l'année, sinon 422), sinon celui en cours, sinon le dernier commencé.
 */
final class PeriodResolver
{
    public function __construct(
        private readonly PeriodTree $tree,
        private readonly CurrentPeriod $current,
        private readonly SecondaryClock $clock,
    ) {
    }

    public function resolve(string $tenantId, string $establishmentId, ?string $academicYearId, ?string $periodId): DashboardPeriod
    {
        $today = $this->clock->today();
        $requested = null;
        if ($periodId !== null) {
            $requested = $this->tree->find($tenantId, $establishmentId, $periodId);
            if ($requested === null || !in_array($requested->kind, DashboardPeriod::TERM_KINDS, true)) {
                throw HttpException::validation(['period_id' => ['Trimestre ou semestre inconnu dans cet établissement.']]);
            }
            if ($academicYearId !== null && $academicYearId !== $requested->academicYearId) {
                throw HttpException::validation(['period_id' => ['Ce trimestre n\'appartient pas à l\'année demandée.']]);
            }
            $academicYearId = $requested->academicYearId;
        }
        $running = $academicYearId === null ? $this->current->at($tenantId, $establishmentId, $today) : null;
        $academicYearId ??= $running?->academicYearId;
        if ($academicYearId === null || $academicYearId === '') {
            throw HttpException::validation(['academic_year_id' => ['Aucune période en cours : précisez l\'année scolaire.']]);
        }

        return $this->build($tenantId, $establishmentId, $academicYearId, $requested, $today);
    }

    private function build(string $tenantId, string $establishmentId, string $academicYearId, ?PeriodInfo $requested, string $today): DashboardPeriod
    {
        $periods = $this->tree->ofYear($tenantId, $establishmentId, $academicYearId);
        $terms = array_values(array_filter($periods, static fn (PeriodInfo $p): bool => in_array($p->kind, DashboardPeriod::TERM_KINDS, true)));
        usort($terms, static fn (PeriodInfo $a, PeriodInfo $b): int => [$a->startsOn, $a->ordinal] <=> [$b->startsOn, $b->ordinal]);
        $lastSequenceEnd = [];
        $yearPeriod = null;
        $label = null;
        foreach ($periods as $period) {
            $label ??= $period->academicYearLabel;
            if ($period->kind === 'sequence' && $period->parentId !== null) {
                $lastSequenceEnd[$period->parentId] = max($lastSequenceEnd[$period->parentId] ?? '', $period->endsOn);
            } elseif (!in_array($period->kind, [...DashboardPeriod::TERM_KINDS, 'sequence'], true)) {
                $yearPeriod = $period;
            }
        }
        $term = $requested;
        if ($term === null) {
            foreach ($terms as $candidate) {
                if ($candidate->startsOn <= $today) {
                    $term = $candidate;
                }
            }
        }
        $previous = null;
        foreach ($terms as $candidate) {
            if ($term !== null && $candidate->startsOn < $term->startsOn) {
                $previous = $candidate;
            }
        }
        $starts = array_map(static fn (PeriodInfo $p): string => $p->startsOn, $periods);
        $ends = array_map(static fn (PeriodInfo $p): string => $p->endsOn, $periods);

        return new DashboardPeriod(
            $academicYearId,
            $label,
            $starts === [] ? null : min($starts),
            $ends === [] ? null : max($ends),
            $terms,
            $term,
            $previous,
            $yearPeriod,
            $lastSequenceEnd,
            $today,
        );
    }
}
