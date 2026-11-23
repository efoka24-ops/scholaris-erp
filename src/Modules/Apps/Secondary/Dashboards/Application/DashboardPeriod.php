<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/**
 * Cadre temporel d'un tableau de bord : année scolaire, ses trimestres (ou semestres), le trimestre retenu et celui qui le précède
 * (référence de l'objectif O7), la période « année » si elle existe, et la fin de la dernière séquence de chaque trimestre (O2).
 */
final class DashboardPeriod
{
    public const TERM_KINDS = ['trimester', 'semester'];

    /**
     * @param list<PeriodInfo> $terms trimestres de l'année, dans l'ordre
     * @param array<string, string> $lastSequenceEnd fin de la dernière séquence par trimestre (sinon fin du trimestre)
     */
    public function __construct(
        public readonly string $academicYearId,
        public readonly ?string $academicYearLabel,
        public readonly ?string $yearStart,
        public readonly ?string $yearEnd,
        public readonly array $terms,
        public readonly ?PeriodInfo $term,
        public readonly ?PeriodInfo $previousTerm,
        public readonly ?PeriodInfo $yearPeriod,
        public readonly array $lastSequenceEnd,
        public readonly string $today,
    ) {
    }

    public function lastSequenceEndOf(PeriodInfo $term): string
    {
        return $this->lastSequenceEnd[$term->id] ?? $term->endsOn;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $term = static fn (?PeriodInfo $p): ?array => $p === null ? null : ['id' => $p->id, 'kind' => $p->kind, 'ordinal' => $p->ordinal, 'starts_on' => $p->startsOn, 'ends_on' => $p->endsOn];

        return [
            'academic_year_id' => $this->academicYearId,
            'academic_year_label' => $this->academicYearLabel,
            'year_start' => $this->yearStart,
            'year_end' => $this->yearEnd,
            'term' => $term($this->term),
            'previous_term' => $term($this->previousTerm),
            'terms' => array_map(static fn (PeriodInfo $p): ?array => $term($p), $this->terms),
            'today' => $this->today,
        ];
    }
}
