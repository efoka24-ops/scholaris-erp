<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Psr\Container\ContainerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Contracts\PeriodAggregatingStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Assessment\Domain\SubjectStatistics;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Calcul des résultats d'une classe pour une période (T121) et publication aux familles. Le calcul est celui du
 * RuleEngine (ou de la stratégie que les règles nomment, S6) avec les règles en vigueur pour la structure (S5) ;
 * recalculer ne touche jamais aux décisions du conseil ou du jury.
 *
 * Session (S7) : « normal » ne lit que les épreuves de session normale (comportement historique) ; « resit » lit en
 * plus les épreuves de rattrapage, que la stratégie combine (le moteur standard remplace la note du groupe).
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class ResultService
{
    /** @var array<string, true> périodes en cours d'agrégation (garde contre un cycle de parents) */
    private array $aggregating = [];

    public function __construct(
        private readonly Db $db,
        private readonly CatalogRepository $catalog,
        private readonly GradeRepository $grades,
        private readonly ResultRepository $results,
        private readonly Roster $roster,
        private readonly RuleService $rules,
        private readonly RuleEngine $engine,
        private readonly RuleCalculation $calculation,
        private readonly AssessmentService $assessments,
        private readonly AssessmentSettings $settings,
        private readonly AssessmentClock $clock,
        private readonly AuditLog $audit,
        private readonly DeliberationGuards $guards,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Données du moteur pour une classe, une période et une session.
     *
     * @return array{
     *   students: list<array{person_id: string, name: string, last_name: string, matricule: string|null}>,
     *   assessments: list<array<string, mixed>>,
     *   subjects: array<string, array<string, mixed>>,
     *   inputs: array<string, list<Unit>>,
     *   year: string|null
     * }
     */
    public function inputs(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId = null, string $session = AssessmentService::NORMAL): array
    {
        $students = $this->roster->students($tenantId, $establishmentId, $structureId);
        $assessments = array_values(array_filter(
            $this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId, $subjectId),
            static fn (array $a): bool => $a['grading_mode'] === 'numeric'
                && ($session === AssessmentService::RESIT || ($a['exam_session'] ?? AssessmentService::NORMAL) === AssessmentService::NORMAL),
        ));
        $subjects = $this->catalog->subjectsByIds($tenantId, array_values(array_unique(array_map(static fn (array $a): string => (string) $a['subject_id'], $assessments))));
        $elements = $this->catalog->elementsByIds($tenantId, array_values(array_unique(array_filter(array_map(static fn (array $a): ?string => $a['element_id'] === null ? null : (string) $a['element_id'], $assessments)))));

        $cells = [];
        foreach ($this->grades->ofAssessments($tenantId, array_map(static fn (array $a): string => (string) $a['id'], $assessments)) as $grade) {
            $cells[(string) $grade['assessment_id']][(string) $grade['person_id']] = $grade;
        }

        $year = null;
        foreach ($assessments as $assessment) {
            $year ??= $assessment['academic_year_id'] === null ? null : (string) $assessment['academic_year_id'];
        }
        $rules = $this->rules->activeRules($tenantId, $establishmentId, $year, $structureId);

        $inputs = [];
        foreach ($students as $student) {
            $bySubject = [];
            foreach ($assessments as $assessment) {
                $subject = $subjects[(string) $assessment['subject_id']] ?? null;
                if ($subject === null) {
                    continue;
                }
                $subjectKey = (string) $subject['id'];
                $groupKey = $assessment['element_id'] === null ? '_' : (string) $assessment['element_id'];
                $element = $groupKey === '_' ? null : ($elements[$groupKey] ?? null);
                $bySubject[$subjectKey] ??= [
                    'subject_id' => $subjectKey, 'code' => (string) $subject['code'], 'name' => (string) $subject['name'], 'kind' => (string) $subject['kind'],
                    'coefficient' => (float) $subject['coefficient'], 'credits' => (int) $subject['credits'], 'groups' => [],
                ];
                $bySubject[$subjectKey]['groups'][$groupKey] ??= [
                    'element_id' => $groupKey === '_' ? null : $groupKey,
                    'code' => $element === null ? null : (string) ($element['code'] ?? $element['name']),
                    'coefficient' => $groupKey === '_' ? 1.0 : (float) ($element['coefficient'] ?? 1.0),
                    'credits' => $element === null ? 0 : (int) $element['credits'],
                    'entries' => [],
                ];
                $grade = $cells[(string) $assessment['id']][$student['person_id']] ?? null;
                $absence = $grade === null || $grade['absence'] === null ? null : (string) $grade['absence'];
                $bySubject[$subjectKey]['groups'][$groupKey]['entries'][] = [
                    'value' => $grade === null || $grade['score'] === null ? null : (float) $grade['score'],
                    'max' => (float) $assessment['max_score'],
                    'weight' => (float) $assessment['weight'],
                    'absence' => $absence,
                    'effect' => $absence === null ? null : $rules->absenceEffect($absence),
                    'kind' => (string) $assessment['kind'],
                    'session' => (string) ($assessment['exam_session'] ?? AssessmentService::NORMAL),
                ];
            }
            $list = [];
            foreach ($bySubject as $subject) {
                $subject['groups'] = array_values($subject['groups']);
                $list[] = $subject;
            }
            $inputs[$student['person_id']] = $list;
        }

        return ['students' => $students, 'assessments' => $assessments, 'subjects' => $subjects, 'inputs' => $inputs, 'year' => $year];
    }

    /** Règles en vigueur pour une classe et une période (année lue sur ses épreuves). */
    public function rulesFor(string $tenantId, string $establishmentId, string $structureId, string $periodId): RuleSet
    {
        $year = null;
        foreach ($this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId) as $assessment) {
            $year ??= $assessment['academic_year_id'] === null ? null : (string) $assessment['academic_year_id'];
        }

        return $this->rules->activeRules($tenantId, $establishmentId, $year, $structureId);
    }

    /**
     * Toutes les épreuves d'une classe pour une période (numériques et qualitatives).
     *
     * @return list<array<string, mixed>>
     */
    public function allAssessments(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $subjectId): array
    {
        return $this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId, $subjectId);
    }

    /**
     * Calcule et enregistre les résultats d'une classe pour une période et une session ; renvoie aussi les règles
     * appliquées. Si l'option assessment.deliberation.freeze_results est active et qu'une délibération close couvre la
     * cellule, les résultats figés sont renvoyés tels quels (RG-DEL-4).
     *
     * @return array{rows: list<array<string, mixed>>, rules: RuleSet}
     */
    public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null, string $session = AssessmentService::NORMAL): array
    {
        $aggregated = $this->aggregateParent($tenantId, $establishmentId, $structureId, $periodId, $periodLabel, $session);
        if ($aggregated !== null) {
            return $aggregated;
        }
        $data = $this->inputs($tenantId, $establishmentId, $structureId, $periodId, null, $session);
        $rules = $this->rules->activeRules($tenantId, $establishmentId, $data['year'], $structureId);

        $deliberation = $this->results->deliberationOf($tenantId, $establishmentId, $structureId, $periodId, $session);
        if ($deliberation !== null && $deliberation['status'] === 'closed' && $this->settings->deliberationFreezesResults($tenantId, $establishmentId)) {
            return ['rows' => $this->results->resultsOf($tenantId, $establishmentId, $structureId, $periodId, $session), 'rules' => $rules];
        }

        $computed = [];
        $averages = [];
        foreach ($data['students'] as $student) {
            $computed[$student['person_id']] = $this->calculation->compute($rules, $data['inputs'][$student['person_id']], $session);
            $averages[$student['person_id']] = $computed[$student['person_id']]['average'];
        }
        $ranks = $this->engine->ranks($averages);
        $size = count($data['students']);
        $now = $this->clock->now();

        $details = [];
        $extras = [];
        foreach ($data['students'] as $student) {
            $personId = $student['person_id'];
            $result = $computed[$personId];
            $list = [];
            foreach ($result['subjects'] as $subjectId => $average) {
                $detail = ['subject_id' => $subjectId, 'code' => $data['subjects'][$subjectId]['code'] ?? null, 'name' => $data['subjects'][$subjectId]['name'] ?? null, 'average' => $average];
                foreach ($result['units'][$subjectId] ?? [] as $key => $value) {
                    if (!isset($detail[$key])) {
                        $detail[$key] = $value;
                    }
                }
                $list[] = $detail;
            }
            $details[$personId] = $list;
            $extras[$personId] = $result['extras'] ?? [];
        }
        [$details, $extras] = $this->withSubjectRanks($tenantId, $establishmentId, $rules, $details, $extras, $averages);

        $rows = $this->db->transaction(function () use ($tenantId, $establishmentId, $structureId, $periodId, $periodLabel, $session, $data, $rules, $computed, $ranks, $size, $now, $details, $extras): array {
            $rows = [];
            foreach ($data['students'] as $student) {
                $personId = $student['person_id'];
                $result = $computed[$personId];
                $existing = $this->results->resultOf($tenantId, $establishmentId, $personId, $periodId, $session);
                $fields = [
                    'structure_id' => $structureId, 'period_label' => $periodLabel ?? ($existing['period_label'] ?? $periodId),
                    'average' => $result['average'], 'rank_in_class' => $ranks[$personId], 'class_size' => $size,
                    'credits_earned' => $result['credits_total'] > 0 ? $result['credits_earned'] : null,
                    'credits_total' => $result['credits_total'] > 0 ? $result['credits_total'] : null,
                    'mention' => $result['mention'], 'proposal' => $result['deliberation_proposal'],
                    'rule_version' => $rules->version, 'details' => json_encode($details[$personId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'computed_extras' => $extras[$personId] === [] ? null : json_encode($extras[$personId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'computed_at' => $now,
                ];
                $rows[] = $this->store($tenantId, $establishmentId, $personId, $periodId, $session, $existing, $fields);
            }

            return $rows;
        });

        return ['rows' => $rows, 'rules' => $rules];
    }

    /**
     * Calcul d'une période qui a des enfants (SS3) : année → trimestres → séquences, récursivement. Seulement si la
     * stratégie nommée par les règles implémente Contracts\PeriodAggregatingStrategy ; sinon null et la période est
     * calculée sur ses propres épreuves (comportement historique). Chaque enfant est d'abord calculé (et enregistré), puis
     * la stratégie reçoit, par élève, les résumés des enfants dans l'ordre des périodes (rang, date de début) : moyenne,
     * crédits et `units` indexé par identifiant de matière (average, code, name et le détail de l'enfant). Le détail de
     * la période parente reprend la forme habituelle : liste d'entrées {subject_id, code, name, average, ...}.
     *
     * @return array{rows: list<array<string, mixed>>, rules: RuleSet}|null
     */
    private function aggregateParent(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel, string $session): ?array
    {
        if (isset($this->aggregating[$periodId])) {
            return null;
        }
        $period = $this->results->period($tenantId, $establishmentId, $periodId);
        if ($period === null) {
            return null;
        }
        $children = $this->results->childPeriods($tenantId, $establishmentId, $periodId);
        if ($children === []) {
            return null;
        }
        $rules = $this->rules->activeRules($tenantId, $establishmentId, $period['academic_year_id'], $structureId);
        $strategy = $this->aggregatingStrategy($rules->strategy);
        if ($strategy === null) {
            return null;
        }

        $this->aggregating[$periodId] = true;
        try {
            $childRows = [];
            foreach ($children as $childId) {
                $childRows[$childId] = [];
                foreach ($this->compute($tenantId, $establishmentId, $structureId, $childId, null, $session)['rows'] as $row) {
                    $childRows[$childId][(string) $row['person_id']] = $row;
                }
            }
        } finally {
            unset($this->aggregating[$periodId]);
        }

        $roster = $this->roster->students($tenantId, $establishmentId, $structureId);
        $calculationRules = $rules->toCalculationRules();
        $averages = [];
        $aggregates = [];
        $details = [];
        $extras = [];
        foreach ($roster as $student) {
            $personId = (string) $student['person_id'];
            $summaries = [];
            $labels = [];
            foreach ($children as $childId) {
                $row = $childRows[$childId][$personId] ?? null;
                if ($row === null) {
                    continue;
                }
                $units = self::unitsOf($row['details'] ?? null);
                foreach ($units as $subjectId => $unit) {
                    $labels[$subjectId] = ['code' => $unit['code'] ?? null, 'name' => $unit['name'] ?? null];
                }
                $summaries[] = [
                    'period_id' => $childId, 'average' => $row['average'] === null ? null : (float) $row['average'],
                    'credits_earned' => (int) ($row['credits_earned'] ?? 0), 'credits_total' => (int) ($row['credits_total'] ?? 0), 'units' => $units,
                ];
            }
            $aggregate = $strategy->aggregatePeriods($calculationRules, $summaries);
            $aggregates[$personId] = $aggregate;
            $averages[$personId] = $aggregate['average'] === null ? null : (float) $aggregate['average'];
            $list = [];
            foreach ($aggregate['units'] as $subjectId => $unit) {
                $subjectId = (string) $subjectId;
                $detail = ['subject_id' => $subjectId, 'code' => $labels[$subjectId]['code'] ?? null, 'name' => $labels[$subjectId]['name'] ?? null, 'average' => $unit['average'] ?? null];
                foreach ($unit as $key => $value) {
                    if (!array_key_exists($key, $detail)) {
                        $detail[$key] = $value;
                    }
                }
                $list[] = $detail;
            }
            $details[$personId] = $list;
            $extras[$personId] = $aggregate['extras'] ?? [];
        }
        [$details, $extras] = $this->withSubjectRanks($tenantId, $establishmentId, $rules, $details, $extras, $averages);
        $ranks = $this->engine->ranks($averages);
        $now = $this->clock->now();

        $rows = $this->db->transaction(function () use ($tenantId, $establishmentId, $structureId, $periodId, $periodLabel, $session, $roster, $rules, $aggregates, $ranks, $details, $extras, $now): array {
            $rows = [];
            foreach ($roster as $student) {
                $personId = (string) $student['person_id'];
                $aggregate = $aggregates[$personId];
                $existing = $this->results->resultOf($tenantId, $establishmentId, $personId, $periodId, $session);
                $fields = [
                    'structure_id' => $structureId, 'period_label' => $periodLabel ?? ($existing['period_label'] ?? $periodId),
                    'average' => $aggregate['average'], 'rank_in_class' => $ranks[$personId], 'class_size' => count($roster),
                    'credits_earned' => $aggregate['credits_total'] > 0 ? $aggregate['credits_earned'] : null,
                    'credits_total' => $aggregate['credits_total'] > 0 ? $aggregate['credits_total'] : null,
                    'mention' => $aggregate['average'] === null ? null : $this->engine->mention($rules, (float) $aggregate['average']), 'proposal' => null,
                    'rule_version' => $rules->version, 'details' => json_encode($details[$personId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'computed_extras' => $extras[$personId] === [] ? null : json_encode($extras[$personId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'computed_at' => $now,
                ];
                $rows[] = $this->store($tenantId, $establishmentId, $personId, $periodId, $session, $existing, $fields);
            }

            return $rows;
        });

        return ['rows' => $rows, 'rules' => $rules];
    }

    private function aggregatingStrategy(string $key): ?PeriodAggregatingStrategy
    {
        foreach (AssessmentExtensions::collect($this->container, AssessmentExtensions::STRATEGIES, RuleStrategy::class) as $candidate) {
            if ($candidate instanceof PeriodAggregatingStrategy && $candidate->key() === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Détail enregistré d'un résultat, indexé par identifiant de matière. Accepte la liste habituelle d'entrées
     * {subject_id, ...} et, pour les résultats agrégés enregistrés avant SS3, une table déjà indexée.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function unitsOf(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $isList = array_is_list($decoded);
        $units = [];
        foreach ($decoded as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!$isList) {
                $units[(string) $key] = $entry;
            } elseif (isset($entry['subject_id'])) {
                $units[(string) $entry['subject_id']] = $entry;
            }
        }

        return $units;
    }

    /**
     * Rang et statistiques par matière (SS4) si assessment.subject_ranks est vrai ; sinon détail et valeurs inchangés.
     * Les valeurs calculées reçoivent class_avg, class_min et class_max (celles de la stratégie priment).
     *
     * @param array<string, list<array<string, mixed>>> $details
     * @param array<string, array<string, mixed>> $extras
     * @param array<string, float|null> $averages
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, array<string, mixed>>}
     */
    private function withSubjectRanks(string $tenantId, string $establishmentId, RuleSet $rules, array $details, array $extras, array $averages): array
    {
        if (!$this->settings->subjectRanks($tenantId, $establishmentId)) {
            return [$details, $extras];
        }
        $applied = (new SubjectStatistics($this->engine))->apply($rules, $details, $averages);
        foreach ($extras as $personId => $values) {
            $extras[$personId] = $values + $applied['class'];
        }

        return [$applied['details'], $extras];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, scalar|null> $fields
     * @return array<string, mixed>
     */
    private function store(string $tenantId, string $establishmentId, string $personId, string $periodId, string $session, ?array $existing, array $fields): array
    {
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->results->insert('results', $fields + [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'person_id' => $personId, 'period_id' => $periodId,
                'exam_session' => $session, 'decision' => null, 'status' => 'computed', 'published_at' => null,
            ]);
        } else {
            $id = (string) $existing['id'];
            $this->results->update('results', $tenantId, $id, $fields);
        }

        return $this->results->result($tenantId, $establishmentId, $id) ?? [];
    }

    /**
     * Publication aux familles : les épreuves de la session passent à l'état « publié » selon le cycle de l'application
     * (droit et 2FA compris), les résultats sont recalculés puis publiés. Les décisions du conseil et du jury doivent
     * être arrêtées avant ; les gardes de délibération (signature, 2FA, empreinte : S8) sont consultées.
     *
     * @return list<array<string, mixed>>
     */
    public function publish(Principal $principal, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel, string $session = AssessmentService::NORMAL): array
    {
        $tenantId = $principal->tenantId;
        $council = $session === AssessmentService::NORMAL ? $this->results->councilOf($tenantId, $establishmentId, $structureId, $periodId) : null;
        if ($council !== null && $council['status'] !== 'validated') {
            throw HttpException::conflict('Le conseil de classe n\'a pas encore validé ses décisions. Validez-les avant de publier les résultats.');
        }
        $deliberation = $this->results->deliberationOf($tenantId, $establishmentId, $structureId, $periodId, $session);
        if ($deliberation !== null && $deliberation['status'] !== 'closed') {
            throw HttpException::conflict('La délibération n\'est pas close. Clôturez-la avant de publier les résultats.');
        }
        if ($deliberation !== null) {
            $this->guards->assert(DeliberationGuard::PUBLISH, $principal, $deliberation);
        }

        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId);
        $sheetSession = $this->hasResitAssessments($tenantId, $establishmentId, $structureId, $periodId) ? $session : null;

        $out = $this->db->transaction(function () use ($principal, $establishmentId, $structureId, $periodId, $periodLabel, $lifecycle, $tenantId, $session, $sheetSession): array {
            $this->assessments->transitionSheet($principal, $establishmentId, $structureId, $periodId, null, $lifecycle->published, $sheetSession);
            $this->compute($tenantId, $establishmentId, $structureId, $periodId, $periodLabel, $session);
            $now = $this->clock->now();
            $out = [];
            foreach ($this->results->resultsOf($tenantId, $establishmentId, $structureId, $periodId, $session) as $row) {
                $this->results->update('results', $tenantId, (string) $row['id'], ['status' => 'published', 'published_at' => $row['published_at'] ?? $now]);
                $out[] = $this->results->result($tenantId, $establishmentId, (string) $row['id']) ?? [];
            }
            $this->audit->append($tenantId, $principal->userId, 'assessment.results.published', 'structure', $structureId, null, ['period_id' => $periodId, 'exam_session' => $session, 'count' => count($out)]);

            return $out;
        });
        if ($deliberation !== null) {
            $this->guards->after(DeliberationGuard::PUBLISH, $principal, $deliberation);
        }

        return $out;
    }

    /** Recalcule une classe après une correction approuvée, pour chaque session déjà calculée. */
    public function refreshIfComputed(string $tenantId, string $establishmentId, string $structureId, string $periodId): void
    {
        foreach (AssessmentService::SESSIONS as $session) {
            if ($this->results->resultsOf($tenantId, $establishmentId, $structureId, $periodId, $session) !== []) {
                $this->compute($tenantId, $establishmentId, $structureId, $periodId, null, $session);
            }
        }
    }

    private function hasResitAssessments(string $tenantId, string $establishmentId, string $structureId, string $periodId): bool
    {
        return $this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId, null, AssessmentService::RESIT) !== [];
    }
}
