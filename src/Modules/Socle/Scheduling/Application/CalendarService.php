<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\SchedulingStore;

/** Calendrier académique d'un établissement : semestres, examens, rattrapages, délibérations, jours fériés. */
final class CalendarService
{
    public function __construct(private readonly SchedulingStore $store, private readonly SchedulingClock $clock)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: string|null} */
    public function search(string $tenantId, string $establishmentId, ListQuery $query): array
    {
        $conditions = [];
        foreach (['kind', 'academic_year_id', 'structure_id'] as $field) {
            if (isset($query->filters[$field])) {
                $conditions[$field] = $query->filters[$field];
            }
        }
        $sql = [];
        $params = [];
        foreach (['from' => ['ends_on >= ?', 'from'], 'to' => ['starts_on <= ?', 'to']] as $key => [$clause, $label]) {
            if (isset($query->filters[$key])) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $query->filters[$key]) !== 1) {
                    throw HttpException::validation(['filter[' . $label . ']' => ['La date doit être au format AAAA-MM-JJ.']], 'Paramètres de liste invalides.');
                }
                $sql[] = $clause;
                $params[] = $query->filters[$key];
            }
        }

        return $this->store->page('calendar_events', $tenantId, $establishmentId, $conditions, implode(' AND ', $sql), $params, $query);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->find('calendar_events', $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Événement introuvable.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data, string $userId): array
    {
        $data += ['ends_on' => $data['starts_on']];
        $this->assertCoherent($data);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('calendar_events', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'academic_year_id' => $data['academic_year_id'] ?? null,
            'structure_id' => $data['structure_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $merged = array_replace($this->get($tenantId, $establishmentId, $id), $changes);
        $this->assertCoherent($merged);
        $this->store->update('calendar_events', $tenantId, $establishmentId, $id, [
            'kind' => $merged['kind'],
            'title' => $merged['title'],
            'starts_on' => $merged['starts_on'],
            'ends_on' => $merged['ends_on'],
            'academic_year_id' => $merged['academic_year_id'],
            'structure_id' => $merged['structure_id'],
            'notes' => $merged['notes'],
            'updated_at' => $this->clock->now(),
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->get($tenantId, $establishmentId, $id);
        $this->store->delete('calendar_events', $tenantId, $establishmentId, ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    private function assertCoherent(array $data): void
    {
        if ($data['ends_on'] < $data['starts_on']) {
            throw HttpException::validation(['ends_on' => ['La date de fin ne peut pas précéder la date de début.']]);
        }
    }
}
