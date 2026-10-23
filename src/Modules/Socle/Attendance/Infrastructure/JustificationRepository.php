<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Justificatifs d'absence. */
final class JustificationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM absence_justifications WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach (['status' => 'status', 'person_id' => 'person_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $where .= ' AND ' . $column . ' = ?';
                $params[] = $filters[$filter];
            }
        }

        return $this->db->page('absence_justifications', $where, $params, $query);
    }

    /** @param array<string, scalar|null> $row */
    public function insert(array $row): void
    {
        $this->db->insert('absence_justifications', $row);
    }

    /** @param array<string, scalar|null> $set */
    public function update(string $tenantId, string $id, array $set): void
    {
        $this->db->update('absence_justifications', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /** Identifiant d'un justificatif accepté qui couvre ce jour pour cette personne, sinon null. */
    public function acceptedCovering(string $tenantId, string $establishmentId, string $personId, string $date): ?string
    {
        $row = $this->db->one(
            "SELECT id FROM absence_justifications WHERE tenant_id = ? AND establishment_id = ? AND person_id = ? AND status = 'accepted' AND absence_from <= ? AND absence_to >= ? ORDER BY id LIMIT 1",
            [$tenantId, $establishmentId, $personId, $date, $date],
        );

        return $row === null ? null : (string) $row['id'];
    }

    /** Marque comme justifiées les absences de la personne sur la période ; renvoie leur nombre. */
    public function justifyAbsences(string $tenantId, string $establishmentId, string $personId, string $from, string $to, string $justificationId, string $now): int
    {
        return $this->db->exec(
            "UPDATE attendance_records SET justified = 1, justification_id = ?, updated_at = ?
             WHERE tenant_id = ? AND establishment_id = ? AND person_id = ? AND status = 'A' AND justified = 0 AND session_date >= ? AND session_date <= ?",
            [$justificationId, $now, $tenantId, $establishmentId, $personId, $from, $to],
        );
    }
}
