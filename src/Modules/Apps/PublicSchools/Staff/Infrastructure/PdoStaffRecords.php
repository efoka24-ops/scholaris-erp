<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Staff\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Fiches de carrière (pub_staff_profiles) et formations (pub_trainings) du personnel, bornées par client. */
final class PdoStaffRecords
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null */
    public function profile(string $tenantId, string $staffId): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_staff_profiles WHERE tenant_id = ? AND staff_id = ?');
        $query->execute([$tenantId, $staffId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, ?string> $values public_status, civil_service_number, contract_number, professional_diploma, service_start_on */
    public function saveProfile(string $tenantId, string $staffId, array $values, string $actorId, string $at): void
    {
        $pdo = $this->connections->pdo();
        if ($this->profile($tenantId, $staffId) === null) {
            $pdo->prepare('INSERT INTO pub_staff_profiles (id, tenant_id, staff_id, public_status, civil_service_number, contract_number, professional_diploma, service_start_on, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $staffId, $values['public_status'], $values['civil_service_number'], $values['contract_number'], $values['professional_diploma'], $values['service_start_on'], $actorId, $at]);

            return;
        }
        $pdo->prepare('UPDATE pub_staff_profiles SET public_status = ?, civil_service_number = ?, contract_number = ?, professional_diploma = ?, service_start_on = ?, updated_by = ?, updated_at = ? WHERE tenant_id = ? AND staff_id = ?')
            ->execute([$values['public_status'], $values['civil_service_number'], $values['contract_number'], $values['professional_diploma'], $values['service_start_on'], $actorId, $at, $tenantId, $staffId]);
    }

    /**
     * @param list<string> $staffIds
     * @return array<string, array<string, mixed>> fiches de plusieurs membres du personnel, par identifiant de fiche
     */
    public function profiles(string $tenantId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }
        $query = $this->connections->pdo()->prepare(sprintf(
            'SELECT * FROM pub_staff_profiles WHERE tenant_id = ? AND staff_id IN (%s)',
            implode(', ', array_fill(0, count($staffIds), '?')),
        ));
        $query->execute([$tenantId, ...$staffIds]);
        $byStaff = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStaff[(string) $row['staff_id']] = $row;
        }

        return $byStaff;
    }

    /** @return array<string, mixed> */
    public function addTraining(string $tenantId, string $staffId, string $title, string $startsOn, ?string $endsOn, ?string $organiser, string $actorId, string $at): array
    {
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare('INSERT INTO pub_trainings (id, tenant_id, staff_id, title, starts_on, ends_on, organiser, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $staffId, $title, $startsOn, $endsOn, $organiser, $actorId, $at]);

        return ['id' => $id, 'title' => $title, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'organiser' => $organiser];
    }

    /** @return list<array<string, mixed>> formations suivies après le curseur */
    public function trainings(string $tenantId, string $staffId, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_trainings WHERE tenant_id = ? AND staff_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute([$tenantId, $staffId, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }
}
