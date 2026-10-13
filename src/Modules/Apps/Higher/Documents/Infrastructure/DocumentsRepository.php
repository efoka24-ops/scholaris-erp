<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/** Dépôt PDO des profils documentaires d'école et du registre des documents émis par l'application (tables hi_*). */
final class DocumentsRepository
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null version la plus récente du profil de l'école */
    public function latestProfile(string $tenantId, string $establishmentId, string $schoolId): ?array
    {
        return $this->one(
            'SELECT * FROM hi_document_school_profiles WHERE tenant_id = ? AND establishment_id = ? AND school_id = ? ORDER BY version DESC LIMIT 1',
            [$tenantId, $establishmentId, $schoolId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function profileVersions(string $tenantId, string $establishmentId, string $schoolId): array
    {
        return $this->all(
            'SELECT * FROM hi_document_school_profiles WHERE tenant_id = ? AND establishment_id = ? AND school_id = ? ORDER BY version DESC',
            [$tenantId, $establishmentId, $schoolId],
        );
    }

    /** @param array<string, scalar|null> $row */
    public function insertProfile(array $row): void
    {
        $this->insert('hi_document_school_profiles', $row);
    }

    /** @param array<string, scalar|null> $row */
    public function insertIssued(array $row): void
    {
        $this->insert('hi_issued_documents', $row);
    }

    /** @return array<string, mixed>|null */
    public function issued(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM hi_issued_documents WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /**
     * @param array<string, string> $where égalités (colonnes du registre)
     * @param array{0: string, 1: list<string>} $scope
     * @return list<array<string, mixed>>
     */
    public function issuedList(string $tenantId, string $establishmentId, array $where, array $scope, int $limit = 200): array
    {
        $sql = 'SELECT * FROM hi_issued_documents WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($where as $column => $value) {
            if (!in_array($column, ['person_id', 'doc_code', 'school_id', 'doc_status'], true)) {
                throw new \InvalidArgumentException('Filtre invalide : ' . $column);
            }
            $sql .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->all($sql . ' AND ' . $scope[0] . ' ORDER BY issued_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)), [...$params, ...$scope[1]]);
    }

    /** @return list<array<string, mixed>> documents d'une personne dans tout le client (portail) */
    public function issuedForPerson(string $tenantId, string $personId): array
    {
        return $this->all('SELECT * FROM hi_issued_documents WHERE tenant_id = ? AND person_id = ? ORDER BY issued_at DESC, id DESC LIMIT 200', [$tenantId, $personId]);
    }

    /** @param array<string, scalar|null> $values */
    public function updateIssued(string $tenantId, string $establishmentId, string $id, array $values): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $this->connections->pdo()->prepare('UPDATE hi_issued_documents SET ' . $sets . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([...array_values($values), $tenantId, $establishmentId, $id]);
    }

    /** @param array<string, scalar|null> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->connections->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($row));
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
