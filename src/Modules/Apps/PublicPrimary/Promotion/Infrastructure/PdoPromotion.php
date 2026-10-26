<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Décisions de passage (pp_promotion_decisions) et séances du conseil des maîtres (pp_councils), bornées par client et par école. */
final class PdoPromotion
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @return array<string, mixed>|null */
    public function forPerson(string $tenantId, string $yearLabel, string $personId): ?array
    {
        return $this->one('SELECT * FROM pp_promotion_decisions WHERE tenant_id = ? AND year_label = ? AND person_id = ?', [$tenantId, $yearLabel, $personId]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_promotion_decisions WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    public function saveProposal(string $tenantId, string $establishmentId, string $yearLabel, string $structureId, string $personId, string $levelCode, string $proposal, int $keyNa, string $policyVersion, string $at): void
    {
        $existing = $this->forPerson($tenantId, $yearLabel, $personId);
        if ($existing === null) {
            $this->pdo()->prepare('INSERT INTO pp_promotion_decisions (id, tenant_id, establishment_id, year_label, structure_id, person_id, level_code, proposal, key_na_count, decision_state, policy_version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $establishmentId, $yearLabel, $structureId, $personId, $levelCode, $proposal, $keyNa, 'proposed', $policyVersion, $at, $at]);

            return;
        }
        if ($existing['decision_state'] !== 'proposed') {
            return;
        }
        $this->pdo()->prepare('UPDATE pp_promotion_decisions SET structure_id = ?, level_code = ?, proposal = ?, key_na_count = ?, policy_version = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$structureId, $levelCode, $proposal, $keyNa, $policyVersion, $at, $tenantId, $existing['id']]);
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $tenantId, string $id, array $values, string $at): void
    {
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new \InvalidArgumentException('Colonne invalide.');
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        $this->pdo()->prepare('UPDATE pp_promotion_decisions SET ' . implode(', ', $sets) . ', updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([...$params, $at, $tenantId, $id]);
    }

    /** Redoublements déjà décidés pour l'élève dans ce niveau, les autres années (RG-PAS-2). */
    public function previousRepeats(string $tenantId, string $personId, string $levelCode, string $exceptYear): int
    {
        $query = $this->pdo()->prepare("SELECT COUNT(*) FROM pp_promotion_decisions WHERE tenant_id = ? AND person_id = ? AND level_code = ? AND year_label <> ? AND decision = 'repeat' AND decision_state = 'final'");
        $query->execute([$tenantId, $personId, $levelCode, $exceptYear]);

        return (int) $query->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function ofClass(string $tenantId, string $establishmentId, string $yearLabel, ?string $structureId): array
    {
        $sql = 'SELECT * FROM pp_promotion_decisions WHERE tenant_id = ? AND establishment_id = ? AND year_label = ?';
        $params = [$tenantId, $establishmentId, $yearLabel];
        if ($structureId !== null) {
            $sql .= ' AND structure_id = ?';
            $params[] = $structureId;
        }
        $query = $this->pdo()->prepare($sql . ' ORDER BY id');
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $attendees */
    public function insertCouncil(string $tenantId, string $establishmentId, string $yearLabel, string $structureId, string $heldOn, array $attendees, string $actorId, string $at): string
    {
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO pp_councils (id, tenant_id, establishment_id, year_label, structure_id, held_on, attendees, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $yearLabel, $structureId, $heldOn, json_encode($attendees, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $actorId, $at]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function council(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_councils WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    public function setMinutes(string $tenantId, string $councilId, string $documentId): void
    {
        $this->pdo()->prepare('UPDATE pp_councils SET minutes_document_id = ? WHERE tenant_id = ? AND id = ?')->execute([$documentId, $tenantId, $councilId]);
    }

    /** @return list<array<string, mixed>> */
    public function decisionsOfCouncil(string $tenantId, string $councilId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pp_promotion_decisions WHERE tenant_id = ? AND council_id = ? ORDER BY id');
        $query->execute([$tenantId, $councilId]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, int> décisions définitives de l'école pour l'année, par « niveau|décision » */
    public function finalCounts(string $tenantId, string $establishmentId, string $yearLabel): array
    {
        $query = $this->pdo()->prepare("SELECT level_code, decision, COUNT(*) AS n FROM pp_promotion_decisions WHERE tenant_id = ? AND establishment_id = ? AND year_label = ? AND decision_state = 'final' GROUP BY level_code, decision");
        $query->execute([$tenantId, $establishmentId, $yearLabel]);
        $counts = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['level_code'] . '|' . (string) $row['decision']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
