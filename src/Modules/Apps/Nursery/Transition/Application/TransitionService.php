<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Transition\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Passage de fin d'année (EX-MAT-21, MQ-09) : proposition automatique (section suivante dans l'ordre des sections de l'école, ou
 * primaire depuis la dernière section), avis de l'éducatrice, décision motivée de la direction. Aucune règle officielle n'est codée :
 * un maintien exige un motif, et la référence d'une demande des parents si `nursery.transition.retain_requires_parent_request` est
 * vrai (valeur de départ : vrai, à confirmer). Une décision appliquée à la clôture d'année est figée.
 */
final class TransitionService
{
    public const TABLE = 'nu_transition_decisions';
    public const PROMOTE = 'promote';
    public const TO_PRIMARY = 'to_primary';
    public const RETAIN = 'retain';
    public const DECISIONS = [self::PROMOTE, self::TO_PRIMARY, self::RETAIN];

    public function __construct(
        private readonly Store $store,
        private readonly SectionService $sections,
        private readonly ChildDirectory $children,
        private readonly PersonDirectory $people,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * Propositions pour les enfants d'une section (idempotent : une proposition existante est gardée telle quelle).
     *
     * @return list<array<string, mixed>>
     */
    public function propose(?Principal $principal, Perimeter $perimeter, string $sectionId): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $this->sections->get($perimeter, $sectionId);
        if ($principal !== null) {
            $this->access->assertWritable($principal, $perimeter, $sectionId, 'section', $sectionId);
        }
        $next = $this->nextSection($perimeter, $sectionId);
        $out = [];
        foreach ($this->sections->childIds($tenantId, $sectionId) as $childId) {
            $enrollment = $this->children->currentEnrollment($tenantId, $establishmentId, $childId);
            if ($enrollment === null) {
                continue;
            }
            $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $childId, 'academic_year_id' => $enrollment->academicYearId], 'id', 1)[0] ?? null;
            if ($existing === null) {
                $now = $this->clock->now();
                $id = (string) new Ulid();
                $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                    'id' => $id, 'learner_id' => $childId, 'academic_year_id' => $enrollment->academicYearId, 'from_structure_id' => $sectionId,
                    'target_structure_id' => $next, 'proposal' => $next !== null ? self::PROMOTE : self::TO_PRIMARY, 'decision' => null, 'educator_opinion' => null,
                    'educator_id' => null, 'decision_reason' => null, 'parent_request_ref' => null, 'decided_by' => null, 'decided_at' => null, 'applied_at' => null,
                    'status' => 'proposed', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $existing = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id);
            }
            $out[] = $this->resource($existing);
        }
        if ($principal !== null) {
            $this->access->audit($principal, 'nursery.transition.proposed', 'section', $sectionId, null, ['count' => count($out)]);
        }

        return $out;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}} */
    public function page(Perimeter $perimeter, Paging $paging, ?string $sectionId, ?string $status): array
    {
        $where = array_filter(['from_structure_id' => $sectionId, 'status' => $status], static fn (?string $v): bool => $v !== null);
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $where, 'from_structure_id, created_at, id', 5000) as $row) {
            if ($perimeter->coversSection((string) $row['from_structure_id'])) {
                $rows[] = $this->resource($row);
            }
        }

        return $paging->slice($rows);
    }

    /** @return array<string, mixed> */
    public function opinion(Principal $principal, Perimeter $perimeter, string $id, string $opinion): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if (!in_array((string) $row['status'], ['proposed', 'opined'], true)) {
            throw HttpException::conflict('La direction a déjà décidé : l’avis n’est plus modifiable.');
        }
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, [
            'educator_opinion' => $opinion, 'educator_id' => $principal->userId, 'status' => 'opined', 'updated_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->access->audit($principal, 'nursery.transition.opinion', 'transition_decision', $id);

        return $this->get($perimeter, $id);
    }

    /**
     * @param array{decision: string, reason?: ?string, parent_request_ref?: ?string, target_section_id?: ?string} $data
     * @return array<string, mixed>
     */
    public function decide(Principal $principal, Perimeter $perimeter, string $id, array $data): array
    {
        $row = $this->writable($principal, $perimeter, $id);
        if ((string) $row['status'] === 'applied') {
            throw HttpException::conflict('Décision déjà appliquée à la clôture d’année : elle est figée.');
        }
        $decision = $data['decision'];
        $reason = isset($data['reason']) && trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : null;
        $request = isset($data['parent_request_ref']) && trim((string) $data['parent_request_ref']) !== '' ? trim((string) $data['parent_request_ref']) : null;
        $errors = [];
        $target = null;
        if ($decision === self::RETAIN) {
            if ($reason === null) {
                $errors['reason'][] = 'Un maintien exige un motif écrit.';
            }
            if ($request === null && $this->settings->bool($perimeter->tenantId, $perimeter->establishmentId, NurserySettings::TRANSITION_RETAIN_NEEDS_REQUEST)) {
                $errors['parent_request_ref'][] = 'Un maintien exige la référence de la demande écrite des parents (paramètre de l’école).';
            }
            $target = (string) $row['from_structure_id'];
        } elseif ($decision === self::PROMOTE) {
            $target = isset($data['target_section_id']) && $data['target_section_id'] !== '' ? (string) $data['target_section_id'] : ($row['target_structure_id'] !== null ? (string) $row['target_structure_id'] : null);
            if ($target === null || !$this->sections->exists($perimeter->tenantId, $perimeter->establishmentId, $target) || $target === (string) $row['from_structure_id']) {
                $errors['target_section_id'][] = 'Section d’accueil introuvable (ou identique) : choisissez la section de l’année prochaine.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, [
            'decision' => $decision, 'target_structure_id' => $target, 'decision_reason' => $reason, 'parent_request_ref' => $request,
            'decided_by' => $principal->userId, 'decided_at' => $now, 'status' => 'decided', 'updated_at' => $now,
        ], ['id' => $id]);
        $this->access->audit($principal, 'nursery.transition.decided', 'transition_decision', $id, ['decision' => $row['decision']], ['decision' => $decision]);

        return $this->get($perimeter, $id);
    }

    /** @return array<string, mixed> */
    public function get(Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Décision introuvable.');
        $this->access->assertReadable($perimeter, (string) $row['from_structure_id']);

        return $this->resource($row);
    }

    /** @return array<string, mixed>|null décision de l'enfant pour cette année */
    public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $academicYearId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? null;
    }

    /** @return list<array<string, mixed>> toutes les lignes brutes d'une année (clôture) */
    public function ofYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'id', 10000);
    }

    public function markApplied(string $tenantId, string $establishmentId, string $id): void
    {
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => 'applied', 'applied_at' => $now, 'updated_at' => $now], ['id' => $id]);
    }

    /** Section suivante dans l'ordre d'affichage de l'école ; null depuis la dernière (passage au primaire). */
    public function nextSection(Perimeter $perimeter, string $sectionId): ?string
    {
        $all = $this->sections->inPerimeter(new Perimeter($perimeter->tenantId, $perimeter->establishmentId, true));
        foreach ($all as $index => $section) {
            if ($section['id'] === $sectionId) {
                return isset($all[$index + 1]) ? (string) $all[$index + 1]['id'] : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, Perimeter $perimeter, string $id): array
    {
        $row = $this->store->find(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, $id) ?? throw HttpException::notFound('Décision introuvable.');
        $this->access->assertWritable($principal, $perimeter, (string) $row['from_structure_id'], 'transition_decision', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        $child = $this->people->find((string) $row['tenant_id'], (string) $row['learner_id']);

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'child_name' => $child?->fullName(), 'academic_year_id' => (string) $row['academic_year_id'],
            'from_section_id' => (string) $row['from_structure_id'], 'target_section_id' => $row['target_structure_id'] !== null ? (string) $row['target_structure_id'] : null,
            'proposal' => (string) $row['proposal'], 'decision' => $row['decision'] !== null ? (string) $row['decision'] : null,
            'educator_opinion' => $row['educator_opinion'] !== null ? (string) $row['educator_opinion'] : null,
            'decision_reason' => $row['decision_reason'] !== null ? (string) $row['decision_reason'] : null,
            'parent_request_ref' => $row['parent_request_ref'] !== null ? (string) $row['parent_request_ref'] : null,
            'decided_at' => $row['decided_at'] !== null ? (string) $row['decided_at'] : null, 'applied_at' => $row['applied_at'] !== null ? (string) $row['applied_at'] : null,
            'status' => (string) $row['status'],
        ];
    }
}
