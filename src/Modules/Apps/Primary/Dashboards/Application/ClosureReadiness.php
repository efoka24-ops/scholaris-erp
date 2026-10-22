<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Apps\Primary\Enrolment\Application\PromotionMapChecker;
use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Contrôles propres au primaire avant la clôture d'une année (EX-PRI-46), servis à l'écran du directeur ET au socle (contrôles
 * `organisation.closure.checks.primary`) :
 *  - `primary.competency_sheets` : feuilles de compétences de l'année qui ont des évaluations mais ne sont pas verrouillées (bloquant) ;
 *  - `primary.promotion_map` : table de passage (classe -> classe suivante) incohérente (bloquant).
 * Un établissement sans niveau primaire reçoit « sans objet » (aucun blocage). Les bulletins et conseils attendent le client (Q10).
 */
final class ClosureReadiness
{
    public const SHEETS = 'primary.competency_sheets';
    public const SHEETS_LABEL = 'Feuilles de compétences non verrouillées';
    public const MAP = 'primary.promotion_map';
    public const MAP_LABEL = 'Table de passage incohérente';

    public function __construct(
        private readonly ClassFigures $classes,
        private readonly SheetProgress $progress,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PromotionMapChecker $promotion,
    ) {
    }

    /**
     * @return array{finding: ClosureFinding, classes: list<array{class_id: string, name: string, periods: list<string>}>}
     */
    public function sheets(string $tenantId, string $establishmentId, string $yearId): array
    {
        $all = $this->classes->all($tenantId, $establishmentId);
        if ($all === []) {
            return ['finding' => ClosureFinding::of(self::SHEETS, self::SHEETS_LABEL, 0, 'Sans objet : aucun niveau primaire.'), 'classes' => []];
        }
        $open = [];
        $count = 0;
        foreach ($all as $class) {
            if ($this->yearOf($tenantId, $class) !== $yearId) {
                continue;
            }
            $states = $this->progress->statesByPeriod($tenantId, $establishmentId, $class->id);
            $periods = [];
            foreach ($this->progress->marksByPeriod($tenantId, $establishmentId, $class->id) as $periodId => $marks) {
                if ($marks > 0 && ($states[$periodId] ?? 'open') === 'open') {
                    $periods[] = $periodId;
                }
            }
            if ($periods !== []) {
                sort($periods);
                $open[] = ['class_id' => $class->id, 'name' => $class->name, 'periods' => $periods];
                $count += count($periods);
            }
        }
        usort($open, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'finding' => ClosureFinding::of(self::SHEETS, self::SHEETS_LABEL, $count, $count > 0 ? sprintf('%d feuille(s) de compétences à verrouiller dans %d classe(s).', $count, count($open)) : null),
            'classes' => $open,
        ];
    }

    /**
     * @return array{finding: ClosureFinding, errors: list<array{code: string, class_structure_id: string, target_structure_id: ?string}>}
     */
    public function promotionMap(string $tenantId, string $establishmentId): array
    {
        $result = $this->promotion->evaluate($tenantId, $establishmentId);
        if ($result['classes'] === 0) {
            return ['finding' => ClosureFinding::of(self::MAP, self::MAP_LABEL, 0, 'Sans objet : aucun niveau primaire.'), 'errors' => []];
        }
        $count = count($result['errors']);

        return [
            'finding' => ClosureFinding::of(self::MAP, self::MAP_LABEL, $count, $count > 0 ? sprintf('%d classe(s) sans classe suivante valide.', $count) : null),
            'errors' => $result['errors'],
        ];
    }

    /** @return array<string, mixed> */
    public function report(string $tenantId, string $establishmentId, string $yearId): array
    {
        $sheets = $this->sheets($tenantId, $establishmentId, $yearId);
        $map = $this->promotionMap($tenantId, $establishmentId);
        $findings = [$sheets['finding'], $map['finding']];
        $blocking = count(array_filter($findings, static fn (ClosureFinding $f): bool => $f->blocks()));

        return [
            'academic_year_id' => $yearId,
            'ready' => $blocking === 0,
            'blocking_count' => $blocking,
            'checks' => array_map(static fn (ClosureFinding $f): array => $f->toArray(), $findings),
            'sheets_to_lock' => $sheets['classes'],
            'promotion_map_errors' => $map['errors'],
        ];
    }

    /** Année des inscriptions de la classe (celle du premier élève inscrit) ; null pour une classe sans élève. */
    private function yearOf(string $tenantId, ClassFigure $class): ?string
    {
        foreach ($class->learnerIds as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->structureId === $class->id) {
                    return $enrollment->academicYearId;
                }
            }
        }

        return null;
    }
}
