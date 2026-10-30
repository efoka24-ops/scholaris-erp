<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Apps\Primary\Enrolment\Application\PromotionMapChecker;
use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Contrôles propres au primaire avant la clôture d'une année (EX-PRI-46), servis à l'écran du directeur ET au socle (contrôles
 * `organisation.closure.checks.primary`) :
 *  - `primary.competency_sheets` : feuilles de compétences de l'année qui ont des évaluations mais ne sont pas verrouillées (bloquant) ;
 *  - `primary.promotion_map` : table de passage (classe -> classe suivante) incohérente (bloquant).
 *  - `primary.councils` et `primary.report_cards` : conseils validés et bulletins émis (paramètres `primary.closure.require_*`).
 * Un établissement sans niveau primaire reçoit « sans objet » (aucun blocage).
 */
final class ClosureReadiness
{
    public const SHEETS = 'primary.competency_sheets';
    public const SHEETS_LABEL = 'Feuilles de compétences non verrouillées';
    public const MAP = 'primary.promotion_map';
    public const MAP_LABEL = 'Table de passage incohérente';
    public const COUNCILS = 'primary.councils';
    public const COUNCILS_LABEL = 'Conseils des maîtres non validés';
    public const CARDS = 'primary.report_cards';
    public const CARDS_LABEL = 'Bulletins non émis';

    public function __construct(
        private readonly ClassFigures $classes,
        private readonly SheetProgress $progress,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PromotionMapChecker $promotion,
        private readonly Store $store,
        private readonly PrimarySettings $settings,
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
        $councils = $this->councils($tenantId, $establishmentId, $yearId);
        $cards = $this->reportCards($tenantId, $establishmentId, $yearId);
        $findings = [$sheets['finding'], $map['finding'], $councils['finding'], $cards['finding']];
        $blocking = count(array_filter($findings, static fn (ClosureFinding $f): bool => $f->blocks()));

        return [
            'academic_year_id' => $yearId,
            'ready' => $blocking === 0,
            'blocking_count' => $blocking,
            'checks' => array_map(static fn (ClosureFinding $f): array => $f->toArray(), $findings),
            'sheets_to_lock' => $sheets['classes'],
            'promotion_map_errors' => $map['errors'],
            'councils_missing' => $councils['classes'],
            'report_cards_missing' => $cards['classes'],
        ];
    }

    /**
     * Conseils des maîtres de l'année : chaque classe qui a des élèves doit avoir `primary.trimesters` conseils VALIDÉS (Q10, Q4 :
     * à confirmer). Désactivable par `primary.closure.require_councils`.
     *
     * @return array{finding: ClosureFinding, classes: list<array{class_id: string, name: string, validated: int, expected: int, open: int}>}
     */
    public function councils(string $tenantId, string $establishmentId, string $yearId): array
    {
        $all = $this->classes->all($tenantId, $establishmentId);
        if ($all === []) {
            return ['finding' => ClosureFinding::of(self::COUNCILS, self::COUNCILS_LABEL, 0, 'Sans objet : aucun niveau primaire.'), 'classes' => []];
        }
        if (!$this->settings->bool($tenantId, $establishmentId, 'primary.closure.require_councils')) {
            return ['finding' => ClosureFinding::of(self::COUNCILS, self::COUNCILS_LABEL, 0, 'Contrôle désactivé par le paramètre primary.closure.require_councils.'), 'classes' => []];
        }
        $expected = $this->settings->int($tenantId, $establishmentId, 'primary.trimesters');
        $missing = [];
        $count = 0;
        foreach ($all as $class) {
            if ($class->learnerIds === [] || $this->yearOf($tenantId, $class) !== $yearId) {
                continue;
            }
            $rows = $this->store->select('pri_councils', $tenantId, $establishmentId, ['class_structure_id' => $class->id, 'academic_year_id' => $yearId], 'id', 50);
            $validated = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'validated'));
            if ($validated < $expected) {
                $missing[] = ['class_id' => $class->id, 'name' => $class->name, 'validated' => $validated, 'expected' => $expected, 'open' => count($rows) - $validated];
                $count += $expected - $validated;
            }
        }
        usort($missing, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'finding' => ClosureFinding::of(self::COUNCILS, self::COUNCILS_LABEL, $count, $count > 0 ? sprintf('%d conseil(s) à tenir ou à valider dans %d classe(s).', $count, count($missing)) : null),
            'classes' => $missing,
        ];
    }

    /**
     * Bulletins : chaque élève d'un conseil validé de l'année a son bulletin trimestriel émis (ou publié). Désactivable par
     * `primary.closure.require_report_cards`.
     *
     * @return array{finding: ClosureFinding, classes: list<array{class_id: string, period_label: string, missing: int}>}
     */
    public function reportCards(string $tenantId, string $establishmentId, string $yearId): array
    {
        if (!$this->settings->bool($tenantId, $establishmentId, 'primary.closure.require_report_cards')) {
            return ['finding' => ClosureFinding::of(self::CARDS, self::CARDS_LABEL, 0, 'Contrôle désactivé par le paramètre primary.closure.require_report_cards.'), 'classes' => []];
        }
        $out = [];
        $count = 0;
        foreach ($this->store->select('pri_councils', $tenantId, $establishmentId, ['academic_year_id' => $yearId, 'status' => 'validated'], 'term_ordinal', 2000) as $council) {
            $issued = [];
            foreach ($this->store->select('pri_report_cards', $tenantId, $establishmentId, ['council_id' => (string) $council['id'], 'card_kind' => 'term'], 'id', 5000, "status IN ('issued', 'published')") as $card) {
                $issued[(string) $card['person_id']] = true;
            }
            $missing = 0;
            foreach ($this->store->select('pri_council_lines', $tenantId, $establishmentId, ['council_id' => (string) $council['id']], 'id', 5000) as $line) {
                $missing += isset($issued[(string) $line['person_id']]) ? 0 : 1;
            }
            if ($missing > 0) {
                $out[] = ['class_id' => (string) $council['class_structure_id'], 'period_label' => (string) $council['period_label'], 'missing' => $missing];
                $count += $missing;
            }
        }

        return [
            'finding' => ClosureFinding::of(self::CARDS, self::CARDS_LABEL, $count, $count > 0 ? sprintf('%d bulletin(s) à émettre.', $count) : null),
            'classes' => $out,
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
