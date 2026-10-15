<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Grades\Domain\EcMark;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;

/**
 * Résultats publiés de l'étudiant (API-16, RG-NOT-4) : seulement ses notes d'évaluations publiées, et seulement sans blocage
 * financier actif sur la consultation des notes (Billing FinancialHolds `results`). Ajoute les résultats de semestre publiés
 * par la délibération (socle ResultsDirectory) et, pour chaque note, la date limite de requête (EX-NOT-07).
 */
final class StudentResultsService
{
    public const SELF = 'self.read';

    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly TeacherAssignments $people,
        private readonly GradesSettings $settings,
        private readonly FinancialHolds $holds,
        private readonly ResultsDirectory $results,
        private readonly GradesClock $clock,
    ) {
    }

    public function personOf(Principal $principal, string $establishmentId, string $permission = self::SELF): string
    {
        if (!$this->policy->can($principal, $establishmentId, $permission)) {
            throw HttpException::forbidden('Permission requise : ' . $permission . '.');
        }

        return $this->people->personId($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Aucun dossier étudiant n\'est lié à ce compte.');
    }

    /** @return array<string, mixed> */
    public function forStudent(Principal $principal, string $establishmentId): array
    {
        $tenantId = $principal->tenantId;
        $personId = $this->personOf($principal, $establishmentId);
        if ($this->holds->isBlocked($tenantId, $establishmentId, $personId, FinancialHolds::RESULTS)) {
            throw new HttpException(403, 'Consultation bloquée', 'La consultation des notes est suspendue jusqu\'à la régularisation de votre situation financière (RG-FIN-5).', 'https://api.scholaris.cm/errors/financial-hold');
        }
        $elements = [];
        foreach ($this->publishedMarks($tenantId, $establishmentId, $personId) as [$mark, $assessment, $publishedAt]) {
            $schoolId = (string) $assessment['school_id'];
            $effects = $this->settings->absenceEffects($tenantId, $establishmentId, $schoolId);
            $key = (string) $assessment['element_id'];
            $elements[$key] ??= [
                'element_id' => $key, 'element_code' => (string) $assessment['element_code'], 'element_title' => (string) $assessment['element_title'],
                'academic_year_id' => (string) $assessment['academic_year_id'], 'semester_ordinal' => (int) $assessment['semester_ordinal'], 'assessments' => [], 'cells' => [],
            ];
            $onTwenty = EcMark::onTwenty($mark['score'] === null ? null : (float) $mark['score'], $mark['absence_code'], (float) $assessment['max_score'], $effects);
            $elements[$key]['cells'][] = ['kind' => (string) $assessment['kind'], 'weight_percent' => (int) $assessment['weight_percent'], 'on_twenty' => $onTwenty, 'pending' => EcMark::pending($mark['absence_code'], $effects)];
            $deadline = $publishedAt === null ? null : (new \DateTimeImmutable($publishedAt))->modify('+' . $this->settings->queryDays($tenantId, $establishmentId, $schoolId) . ' days')->format(DATE_ATOM);
            $elements[$key]['assessments'][] = [
                'assessment_id' => (string) $assessment['id'], 'mark_id' => (string) $mark['id'], 'kind' => (string) $assessment['kind'],
                'label' => (string) $assessment['label'], 'exam_session' => (string) $assessment['exam_session'], 'max_score' => (float) $assessment['max_score'],
                'weight_percent' => (int) $assessment['weight_percent'], 'score' => $mark['score'] === null ? null : (float) $mark['score'],
                'absence' => $mark['absence_code'], 'published_at' => $publishedAt, 'query_until' => $deadline,
                'query_open' => $deadline !== null && $this->clock->now() <= $deadline,
            ];
        }
        $out = [];
        foreach ($elements as $element) {
            $element['ec_mark'] = EcMark::compute($element['cells']);
            unset($element['cells']);
            $out[] = $element;
        }
        usort($out, static fn (array $a, array $b): int => [$b['academic_year_id'], $a['semester_ordinal'], $a['element_code']] <=> [$a['academic_year_id'], $b['semester_ordinal'], $b['element_code']]);
        $semesters = [];
        foreach ($this->results->publishedFor($tenantId, $personId) as $result) {
            if ($result->establishmentId !== $establishmentId) {
                continue;
            }
            $semesters[] = [
                'id' => $result->id, 'period_id' => $result->periodId, 'period_label' => $result->periodLabel, 'session' => $result->session,
                'average' => $result->average, 'credits_earned' => $result->creditsEarned, 'credits_total' => $result->creditsTotal,
                'mention' => $result->mention, 'decision' => $result->decision, 'units' => $result->details,
            ];
        }

        return ['person_id' => $personId, 'elements' => $out, 'semesters' => $semesters];
    }

    /**
     * Notes publiées de la personne : [note, évaluation, date de publication].
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?string}>
     */
    public function publishedMarks(string $tenantId, string $establishmentId, string $personId): array
    {
        $out = [];
        $cache = [];
        foreach ($this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 2000) as $mark) {
            $id = (string) $mark['assessment_id'];
            $cache[$id] ??= $this->store->find(AssessmentService::TABLE, $tenantId, $establishmentId, $id);
            $assessment = $cache[$id];
            if ($assessment === null || !$this->settings->lifecycle($tenantId, $establishmentId, (string) $assessment['school_id'])->isPublished((string) $assessment['status'])) {
                continue;
            }
            $out[] = [$mark, $assessment, self::publishedAt($assessment)];
        }

        return $out;
    }

    /** @param array<string, mixed> $assessment */
    public static function publishedAt(array $assessment): ?string
    {
        $trail = json_decode((string) ($assessment['status_trail'] ?? '[]'), true);
        $at = null;
        foreach (is_array($trail) ? $trail : [] as $entry) {
            if (is_array($entry) && ($entry['to'] ?? null) === $assessment['status'] && is_string($entry['at'] ?? null)) {
                $at = $entry['at'];
            }
        }

        return $at;
    }
}
