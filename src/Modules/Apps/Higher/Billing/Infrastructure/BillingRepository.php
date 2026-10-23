<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Dépôt PDO des tables hi_* du module FIN. Toute requête est bornée au client ET à l'établissement ; les filtres de périmètre
 * (ScopedBySchool) sont ajoutés par l'appelant sous forme de clause et de paramètres positionnels.
 */
final class BillingRepository
{
    public const GRIDS = 'hi_fee_grids';
    public const ACCOUNTS = 'hi_student_accounts';
    public const PLANS = 'hi_payment_plans';
    public const SCHOLARSHIPS = 'hi_scholarships';
    public const SPONSORS = 'hi_sponsors';
    public const PAYMENTS = 'hi_payment_log';
    private const TABLES = [self::GRIDS, self::ACCOUNTS, self::PLANS, self::SCHOLARSHIPS, self::SPONSORS, self::PAYMENTS];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        self::assertTable($table);
        $columns = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->pdo()->prepare($sql)->execute(array_values($row));
    }

    /** @param array<string, scalar|null> $values */
    public function update(string $table, string $tenantId, string $establishmentId, string $id, array $values): int
    {
        self::assertTable($table);
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $statement = $this->pdo()->prepare('UPDATE ' . $table . ' SET ' . $sets . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([...array_values($values), $tenantId, $establishmentId, $id]);

        return $statement->rowCount();
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, string $tenantId, string $establishmentId, string $id): ?array
    {
        self::assertTable($table);

        return $this->one('SELECT * FROM ' . $table . ' WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /**
     * @param array<string, scalar|null> $where égalités supplémentaires (colonne => valeur, null = IS NULL)
     * @param array{0: string, 1: list<string>} $scope clause de périmètre et ses paramètres
     * @return list<array<string, mixed>>
     */
    public function list(string $table, string $tenantId, string $establishmentId, array $where = [], array $scope = ['1 = 1', []], int $limit = 200, string $orderBy = 'created_at DESC, id DESC'): array
    {
        self::assertTable($table);
        $sql = 'SELECT * FROM ' . $table . ' WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($where as $column => $value) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new \InvalidArgumentException('Colonne invalide : ' . $column);
            }
            if ($value === null) {
                $sql .= ' AND ' . $column . ' IS NULL';
            } else {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        $sql .= ' AND ' . $scope[0] . ' ORDER BY ' . $orderBy . ' LIMIT ' . max(1, min(1000, $limit));

        return $this->all($sql, [...$params, ...$scope[1]]);
    }

    /** @return list<array<string, mixed>> comptes d'une personne dans tout le client */
    public function accountsOfPerson(string $tenantId, string $personId): array
    {
        return $this->all('SELECT * FROM hi_student_accounts WHERE tenant_id = ? AND person_id = ? ORDER BY created_at DESC, id DESC', [$tenantId, $personId]);
    }

    /** @return list<array{tenant_id: string, establishment_id: string}> */
    public function activeScopes(): array
    {
        return array_map(static fn (array $r): array => ['tenant_id' => (string) $r['tenant_id'], 'establishment_id' => (string) $r['establishment_id']], $this->all(
            'SELECT DISTINCT tenant_id, establishment_id FROM hi_student_accounts ORDER BY tenant_id, establishment_id',
            [],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function paymentsOfAccount(string $tenantId, string $accountId): array
    {
        return $this->all('SELECT * FROM hi_payment_log WHERE tenant_id = ? AND account_id = ? ORDER BY recorded_at, id', [$tenantId, $accountId]);
    }

    /** @return array<string, mixed>|null */
    public function paymentByPaymentId(string $tenantId, string $paymentId): ?array
    {
        return $this->one('SELECT * FROM hi_payment_log WHERE tenant_id = ? AND payment_id = ?', [$tenantId, $paymentId]);
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->pdo()->prepare($sql);
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
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Table hors du module FIN : ' . $table);
        }
    }
}
