<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/** Affectations d'un enseignant à des classes et matières, avec la charge horaire prévue (en minutes). */
final class AssignmentService
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly StaffService $staff,
    ) {
    }

    /** @return list<array<string, mixed>> affectations de l'établissement actif, avec les minutes déjà validées */
    public function forStaff(string $tenantId, string $establishmentId, string $staffId): array
    {
        $this->staff->get($tenantId, $establishmentId, $staffId);
        $rows = $this->store->where('staff_assignments', $tenantId, ['staff_id' => $staffId, 'establishment_id' => $establishmentId], 'id');
        foreach ($rows as &$row) {
            $row = $this->withRealised($tenantId, $row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, string $staffId, array $data, string $actorId): array
    {
        $member = $this->staff->inTenant($tenantId, $staffId);
        if ($member['status'] === StaffStatus::Left->value) {
            throw HttpException::conflict('Ce membre du personnel a quitté l\'établissement.');
        }
        $this->checkDates($data['starts_on'] ?? null, $data['ends_on'] ?? null);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('staff_assignments', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'staff_id' => $staffId,
            'academic_year_id' => $data['academic_year_id'] ?? null, 'structure_id' => $data['structure_id'] ?? null,
            'subject_id' => $data['subject_id'] ?? null, 'subject_label' => $data['subject_label'] ?? null,
            'session_kind' => $data['session_kind'] ?? 'td', 'planned_minutes' => $data['planned_minutes'] ?? 0,
            'element_ref' => $data['element_ref'] ?? null, 'group_ref' => $data['group_ref'] ?? null, 'level' => $data['level'] ?? null,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null, 'status' => 'active',
            'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->get($tenantId, $establishmentId, $staffId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $staffId, string $id, array $changes): array
    {
        $row = $this->get($tenantId, $establishmentId, $staffId, $id);
        $this->checkDates(
            array_key_exists('starts_on', $changes) ? $changes['starts_on'] : $row['starts_on'],
            array_key_exists('ends_on', $changes) ? $changes['ends_on'] : $row['ends_on'],
        );
        $changes['updated_at'] = $this->clock->now();
        $this->store->update('staff_assignments', $tenantId, $id, $changes);

        return $this->get($tenantId, $establishmentId, $staffId, $id);
    }

    /** @return array<string, mixed> l'affectation supprimée */
    public function delete(string $tenantId, string $establishmentId, string $staffId, string $id): array
    {
        $row = $this->get($tenantId, $establishmentId, $staffId, $id);
        if ($this->store->count('vacation_hours', $tenantId, ['assignment_id' => $id]) > 0) {
            throw HttpException::conflict('Des heures sont rattachées à cette affectation : terminez-la (status = ended) au lieu de la supprimer.');
        }
        $this->store->delete('staff_assignments', $tenantId, ['id' => $id]);

        return $row;
    }

    /**
     * Affectation de l'établissement, quel que soit l'enseignant (contrat AssignmentWriter).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $row = $this->store->find('staff_assignments', $tenantId, $id);

        return $row === null || $row['establishment_id'] !== $establishmentId ? null : $this->withRealised($tenantId, $row);
    }

    /** @return list<array<string, mixed>> affectations actives d'un élément constitutif (et d'un groupe si précisé) */
    public function forElement(string $tenantId, string $establishmentId, string $elementRef, ?string $groupRef): array
    {
        $conditions = ['establishment_id' => $establishmentId, 'element_ref' => $elementRef, 'status' => 'active'];
        if ($groupRef !== null) {
            $conditions['group_ref'] = $groupRef;
        }

        return array_map(fn (array $row): array => $this->withRealised($tenantId, $row), $this->store->where('staff_assignments', $tenantId, $conditions, 'id'));
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $staffId, string $id): array
    {
        $this->staff->get($tenantId, $establishmentId, $staffId);
        $row = $this->store->find('staff_assignments', $tenantId, $id);
        if ($row === null || $row['staff_id'] !== $staffId || $row['establishment_id'] !== $establishmentId) {
            throw HttpException::notFound('Affectation introuvable.');
        }

        return $this->withRealised($tenantId, $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withRealised(string $tenantId, array $row): array
    {
        $sum = $this->store->rows(
            'SELECT COALESCE(SUM(minutes), 0) AS total FROM vacation_hours WHERE tenant_id = ? AND assignment_id = ? AND status = ?',
            [$tenantId, $row['id'], 'validated'],
        );
        $row['validated_minutes'] = (int) $sum[0]['total'];

        return $row;
    }

    private function checkDates(mixed $start, mixed $end): void
    {
        if (is_string($start) && is_string($end) && $end < $start) {
            throw HttpException::validation(['ends_on' => ['La date de fin doit suivre la date de début.']]);
        }
    }
}
