<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Domain\StaffStatus;
use Scholaris\Modules\Socle\Staff\Infrastructure\PdoStore;

/** Fiches du personnel (multi-établissements) et qualifications. L'identité reste dans People : une fiche référence person_id. */
final class StaffService
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly StaffClock $clock,
        private readonly PersonDirectory $persons,
    ) {
    }

    /**
     * @param array<string, mixed> $data person_id, kind, grade, category, employee_number, department_id, hired_on
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data): array
    {
        $personId = (string) $data['person_id'];
        if ($this->persons->find($tenantId, $personId) === null) {
            throw HttpException::notFound('Personne introuvable.');
        }
        if ($this->store->where('staff_members', $tenantId, ['person_id' => $personId], 'id', 1) !== []) {
            throw HttpException::conflict('Cette personne a déjà une fiche du personnel.');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('staff_members', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'home_establishment_id' => $establishmentId,
            'kind' => $data['kind'], 'status' => StaffStatus::Active->value,
            'grade' => $data['grade'] ?? null, 'category' => $data['category'] ?? null,
            'employee_number' => $data['employee_number'] ?? null, 'department_id' => $data['department_id'] ?? null,
            'hired_on' => $data['hired_on'] ?? null, 'left_on' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        if (($changes['status'] ?? null) === StaffStatus::Left->value && !array_key_exists('left_on', $changes)) {
            $changes['left_on'] = $this->clock->today();
        }
        if (isset($changes['status']) && $changes['status'] !== StaffStatus::Left->value && $row['status'] === StaffStatus::Left->value) {
            $changes['left_on'] = null;
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update('staff_members', $tenantId, $id, $changes);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Fiche visible dans l'établissement actif : rattachée à lui ou affectée à une classe de cet établissement.
     *
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $rows = $this->store->rows(
            'SELECT m.* FROM staff_members m WHERE m.tenant_id = ? AND m.id = ? AND ' . $this->visibleClause(),
            [$tenantId, $id, $establishmentId, $establishmentId],
        );
        if ($rows === []) {
            throw HttpException::notFound('Fiche du personnel introuvable.');
        }

        return $rows[0];
    }

    /**
     * Fiche du client, sans condition d'établissement : sert à rattacher le personnel d'un autre établissement du même client.
     *
     * @return array<string, mixed>
     */
    public function inTenant(string $tenantId, string $id): array
    {
        return $this->store->find('staff_members', $tenantId, $id) ?? throw HttpException::notFound('Fiche du personnel introuvable.');
    }

    /**
     * @param list<string>|null $restrictTo null = tout l'établissement
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [[], null];
        }
        $where = ['m.tenant_id = ?', $this->visibleClause()];
        $params = [$tenantId, $establishmentId, $establishmentId];
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $where[] = 'm.id > ?';
            $params[] = $after;
        }
        foreach (['kind', 'status', 'category', 'person_id'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = 'm.' . $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if ($restrictTo !== null) {
            $where[] = 'm.id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        $rows = $this->store->rows(sprintf('SELECT m.* FROM staff_members m WHERE %s ORDER BY m.id LIMIT %d', implode(' AND ', $where), $query->perPage + 1), $params);
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /**
     * Ajoute l'identité (nom, téléphone, e-mail) lue dans People.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function withIdentity(string $tenantId, array $rows): array
    {
        $people = [];
        $ids = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows)));
        foreach ($this->persons->findMany($tenantId, $ids) as $person) {
            $people[$person->id] = $person;
        }
        foreach ($rows as &$row) {
            $person = $people[(string) $row['person_id']] ?? null;
            $row['first_name'] = $person?->firstName;
            $row['last_name'] = $person?->lastName;
            $row['full_name'] = $person?->fullName();
            $row['phone'] = $person?->phone;
            $row['email'] = $person?->email;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function qualifications(string $tenantId, string $establishmentId, string $staffId): array
    {
        $this->get($tenantId, $establishmentId, $staffId);

        return $this->store->where('staff_qualifications', $tenantId, ['staff_id' => $staffId], 'id');
    }

    /**
     * @param array<string, mixed> $data title, institution, obtained_on
     * @return array<string, mixed>
     */
    public function addQualification(string $tenantId, string $establishmentId, string $staffId, array $data): array
    {
        $this->get($tenantId, $establishmentId, $staffId);
        $id = (string) new Ulid();
        $this->store->insert('staff_qualifications', [
            'id' => $id, 'tenant_id' => $tenantId, 'staff_id' => $staffId, 'title' => $data['title'],
            'institution' => $data['institution'] ?? null, 'obtained_on' => $data['obtained_on'] ?? null, 'created_at' => $this->clock->now(),
        ]);

        return $this->store->find('staff_qualifications', $tenantId, $id) ?? [];
    }

    /** @return array<string, mixed> la qualification supprimée */
    public function deleteQualification(string $tenantId, string $establishmentId, string $staffId, string $qualificationId): array
    {
        $this->get($tenantId, $establishmentId, $staffId);
        $row = $this->store->find('staff_qualifications', $tenantId, $qualificationId);
        if ($row === null || $row['staff_id'] !== $staffId) {
            throw HttpException::notFound('Qualification introuvable.');
        }
        $this->store->delete('staff_qualifications', $tenantId, ['id' => $qualificationId]);

        return $row;
    }

    private function visibleClause(): string
    {
        return '(m.home_establishment_id = ? OR EXISTS (SELECT 1 FROM staff_assignments a WHERE a.tenant_id = m.tenant_id AND a.staff_id = m.id AND a.establishment_id = ?))';
    }
}
