<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/**
 * Requête de l'étudiant sur une note publiée (EX-NOT-07, Should ; DOC-19) : dans le délai `higher.grades.query_days` (7 jours)
 * après la publication, une requête ouverte par note ; réponse motivée du chef de département (acceptée ou rejetée). Une requête
 * acceptée donne lieu à une demande de correction de l'enseignant (liée par query_id) : la note n'est jamais modifiée ici.
 */
final class QueryService
{
    public const TABLE = 'hi_grade_queries';

    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly StudentResultsService $results,
        private readonly GradesSettings $settings,
        private readonly GradesClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(Principal $principal, string $establishmentId, string $markId, string $message): array
    {
        $tenantId = $principal->tenantId;
        $personId = $this->results->personOf($principal, $establishmentId, MarkPolicy::QUERY);
        $found = null;
        foreach ($this->results->publishedMarks($tenantId, $establishmentId, $personId) as $entry) {
            if ($entry[0]['id'] === $markId) {
                $found = $entry;
            }
        }
        if ($found === null) {
            throw HttpException::notFound('Note publiée introuvable.');
        }
        [$mark, $assessment, $publishedAt] = $found;
        $days = $this->settings->queryDays($tenantId, $establishmentId, (string) $assessment['school_id']);
        if ($publishedAt === null || $this->clock->now() > (new \DateTimeImmutable($publishedAt))->modify('+' . $days . ' days')->format(DATE_ATOM)) {
            throw HttpException::conflict(sprintf('Délai de requête dépassé (%d jours après la publication).', $days));
        }
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['mark_id' => $markId, 'status' => 'open']) > 0) {
            throw HttpException::conflict('Une requête est déjà ouverte sur cette note.');
        }
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'mark_id' => $markId, 'assessment_id' => $assessment['id'], 'person_id' => $personId,
            'school_id' => $assessment['school_id'], 'department_id' => $assessment['department_id'], 'program_id' => $assessment['program_id'],
            'message' => $message, 'status' => 'open', 'response' => null, 'responded_by' => null, 'responded_at' => null, 'created_at' => $this->clock->now(),
        ]);
        $this->policy->audit($principal, 'higher.grades.query.created', 'higher_grade_query', $id, null, ['mark_id' => $markId]);

        return $this->resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id), $assessment);
    }

    /** @return array<string, mixed> */
    public function respond(Principal $principal, string $establishmentId, string $id, string $decision, string $response): array
    {
        $tenantId = $principal->tenantId;
        $query = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Requête introuvable.');
        $this->policy->assertScoped($principal, $establishmentId, $query, MarkPolicy::VALIDATE, 'higher_grade_query');
        if ($query['status'] !== 'open') {
            throw HttpException::conflict('Requête déjà traitée.');
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, [
            'status' => $decision, 'response' => $response, 'responded_by' => $principal->userId, 'responded_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->policy->audit($principal, 'higher.grades.query.' . $decision, 'higher_grade_query', $id, ['status' => 'open'], ['status' => $decision]);

        return $this->resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * Requêtes de l'étudiant (les siennes) ou du périmètre de l'encadrement.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function visible(Principal $principal, string $establishmentId, array $filters, bool $mine): array
    {
        $tenantId = $principal->tenantId;
        if ($mine) {
            $filters['person_id'] = $this->results->personOf($principal, $establishmentId, MarkPolicy::QUERY);
        } elseif ($this->policy->perimeter($principal, $establishmentId, [MarkPolicy::VALIDATE, MarkPolicy::READ[0]])->isEmpty()) {
            throw HttpException::forbidden('Permission requise : assessment.validate ou higher.grades.read.');
        }
        $out = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, $filters, 'created_at DESC', 500) as $row) {
            if ($mine || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::VALIDATE) || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::READ[0])) {
                $out[] = $this->resource($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $assessment
     * @return array<string, mixed>
     */
    private function resource(array $row, ?array $assessment = null): array
    {
        $assessment ??= $this->store->find(AssessmentService::TABLE, (string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['assessment_id']);

        return [
            'id' => (string) $row['id'], 'mark_id' => (string) $row['mark_id'], 'assessment_id' => (string) $row['assessment_id'],
            'element_code' => $assessment['element_code'] ?? null, 'assessment_label' => $assessment['label'] ?? null,
            'person_id' => (string) $row['person_id'], 'message' => (string) $row['message'], 'status' => (string) $row['status'],
            'response' => $row['response'], 'responded_by' => $row['responded_by'], 'responded_at' => $row['responded_at'], 'created_at' => (string) $row['created_at'],
        ];
    }
}
