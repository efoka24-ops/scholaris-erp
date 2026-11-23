<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/**
 * Tables des examens (pp_exam_*), toujours bornées par client ; les listes et candidats le sont aussi par école. Les sessions et les
 * centres sont nationaux ou départementaux : leur lecture est filtrée par le service (périmètre territorial).
 */
final class PdoExams
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $values colonne => valeur ; `id` généré si absent */
    public function insert(string $table, array $values): string
    {
        self::assertTable($table);
        $values['id'] ??= (string) new Ulid();
        foreach (array_keys($values) as $column) {
            self::assertColumn($column);
        }
        $this->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')')
            ->execute(array_values($values));

        return (string) $values['id'];
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $table, string $tenantId, string $id, array $values): void
    {
        self::assertTable($table);
        if ($values === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($values) as $column) {
            self::assertColumn($column);
            $sets[] = $column . ' = ?';
        }
        $this->pdo()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND id = ?')
            ->execute([...array_values($values), $tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function session(string $tenantId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_exam_sessions WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function sessions(string $tenantId, ?string $yearLabel): array
    {
        return $yearLabel === null
            ? $this->all('SELECT * FROM pp_exam_sessions WHERE tenant_id = ? ORDER BY id', [$tenantId])
            : $this->all('SELECT * FROM pp_exam_sessions WHERE tenant_id = ? AND year_label = ? ORDER BY id', [$tenantId, $yearLabel]);
    }

    /** @return array<string, mixed>|null */
    public function sessionByCode(string $tenantId, string $examCode, string $yearLabel): ?array
    {
        return $this->one('SELECT * FROM pp_exam_sessions WHERE tenant_id = ? AND exam_code = ? AND year_label = ?', [$tenantId, $examCode, $yearLabel]);
    }

    /** @return array<string, mixed>|null */
    public function listOf(string $tenantId, string $sessionId, string $establishmentId): ?array
    {
        return $this->one('SELECT * FROM pp_exam_lists WHERE tenant_id = ? AND session_id = ? AND establishment_id = ?', [$tenantId, $sessionId, $establishmentId]);
    }

    /** @return array<string, mixed>|null */
    public function list(string $tenantId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_exam_lists WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /**
     * @param list<string> $establishmentIds
     * @return list<array<string, mixed>>
     */
    public function listsIn(string $tenantId, string $sessionId, array $establishmentIds): array
    {
        if ($establishmentIds === []) {
            return [];
        }

        return $this->all('SELECT * FROM pp_exam_lists WHERE tenant_id = ? AND session_id = ? AND establishment_id IN (' . self::marks($establishmentIds) . ') ORDER BY establishment_id', [$tenantId, $sessionId, ...$establishmentIds]);
    }

    /** @return array<string, mixed>|null */
    public function candidate(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_exam_candidates WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function candidateOfPerson(string $tenantId, string $sessionId, string $personId): ?array
    {
        return $this->one('SELECT * FROM pp_exam_candidates WHERE tenant_id = ? AND session_id = ? AND person_id = ?', [$tenantId, $sessionId, $personId]);
    }

    /** @return list<array<string, mixed>> */
    public function candidatesOfList(string $tenantId, string $listId): array
    {
        return $this->all('SELECT * FROM pp_exam_candidates WHERE tenant_id = ? AND list_id = ? ORDER BY id', [$tenantId, $listId]);
    }

    /**
     * @param list<string> $establishmentIds
     * @return list<array<string, mixed>>
     */
    public function candidatesIn(string $tenantId, string $sessionId, array $establishmentIds): array
    {
        if ($establishmentIds === []) {
            return [];
        }

        return $this->all('SELECT * FROM pp_exam_candidates WHERE tenant_id = ? AND session_id = ? AND establishment_id IN (' . self::marks($establishmentIds) . ') ORDER BY establishment_id, id', [$tenantId, $sessionId, ...$establishmentIds]);
    }

    /** @return list<array<string, mixed>> candidats d'une école, toutes sessions confondues (agrégats, alertes) */
    public function candidatesOfSchool(string $tenantId, string $establishmentId): array
    {
        return $this->all('SELECT * FROM pp_exam_candidates WHERE tenant_id = ? AND establishment_id = ? ORDER BY id', [$tenantId, $establishmentId]);
    }

    /** @return list<array<string, mixed>> candidats sans alerte d'acte encore envoyée, toutes écoles (tâche planifiée NOT-08) */
    public function candidatesToAlert(): array
    {
        return $this->all("SELECT c.* FROM pp_exam_candidates c WHERE c.birth_act_alerted_at IS NULL AND c.candidate_state <> 'withdrawn' ORDER BY c.tenant_id, c.id", []);
    }

    /** @return array<string, mixed>|null */
    public function centre(string $tenantId, string $sessionId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_exam_centres WHERE tenant_id = ? AND session_id = ? AND id = ?', [$tenantId, $sessionId, $id]);
    }

    /**
     * @param list<string>|null $territoryIds null = tous (niveau national)
     * @return list<array<string, mixed>>
     */
    public function centres(string $tenantId, string $sessionId, ?array $territoryIds): array
    {
        if ($territoryIds === null) {
            return $this->all('SELECT * FROM pp_exam_centres WHERE tenant_id = ? AND session_id = ? ORDER BY code', [$tenantId, $sessionId]);
        }
        if ($territoryIds === []) {
            return [];
        }

        return $this->all('SELECT * FROM pp_exam_centres WHERE tenant_id = ? AND session_id = ? AND territory_id IN (' . self::marks($territoryIds) . ') ORDER BY code', [$tenantId, $sessionId, ...$territoryIds]);
    }

    /**
     * @param list<string> $centreIds
     * @return list<array<string, mixed>>
     */
    public function officials(string $tenantId, string $sessionId, array $centreIds): array
    {
        if ($centreIds === []) {
            return [];
        }

        return $this->all('SELECT * FROM pp_exam_officials WHERE tenant_id = ? AND session_id = ? AND centre_id IN (' . self::marks($centreIds) . ') ORDER BY centre_id, id', [$tenantId, $sessionId, ...$centreIds]);
    }

    /** @return array<string, mixed>|null */
    public function latestTransfer(string $tenantId, string $candidateId): ?array
    {
        return $this->one('SELECT * FROM pp_exam_transfers WHERE tenant_id = ? AND candidate_id = ? ORDER BY id DESC LIMIT 1', [$tenantId, $candidateId]);
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

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, ['pp_exam_sessions', 'pp_exam_lists', 'pp_exam_candidates', 'pp_exam_centres', 'pp_exam_officials', 'pp_exam_transfers'], true)) {
            throw new \InvalidArgumentException('Table hors du module Examens : ' . $table);
        }
    }

    private static function assertColumn(string $column): void
    {
        if (preg_match('/^[a-z_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException('Colonne invalide.');
        }
    }
}
