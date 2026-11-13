<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Comptes de l'APEE (pub_apee_*) et réceptions de fournitures (pub_supply_*), bornés par client et par école. */
final class PdoApee
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @return array<string, mixed>|null */
    public function year(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pub_apee_years WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function yearByLabel(string $tenantId, string $establishmentId, string $label): ?array
    {
        return $this->one('SELECT * FROM pub_apee_years WHERE tenant_id = ? AND establishment_id = ? AND year_label = ?', [$tenantId, $establishmentId, $label]);
    }

    /** @return list<array<string, mixed>> */
    public function years(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        return $this->all('SELECT * FROM pub_apee_years WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit), [$tenantId, $establishmentId, $afterId ?? '']);
    }

    /** @param array<string, scalar|null> $values */
    public function insert(string $table, array $values): void
    {
        if (!in_array($table, ['pub_apee_years', 'pub_apee_entries', 'pub_apee_exemptions', 'pub_supply_receipts', 'pub_supply_lines'], true)) {
            throw new \InvalidArgumentException('Table inconnue.');
        }
        $columns = array_keys($values);
        $this->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($values));
    }

    /** @return array<string, mixed>|null */
    public function entry(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pub_apee_entries WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function entryByUuid(string $tenantId, string $clientUuid): ?array
    {
        return $this->one('SELECT * FROM pub_apee_entries WHERE tenant_id = ? AND client_uuid = ?', [$tenantId, $clientUuid]);
    }

    /** @return list<array<string, mixed>> */
    public function entries(string $tenantId, string $establishmentId, string $yearId, ?string $direction, ?string $afterId, int $limit): array
    {
        $sql = 'SELECT * FROM pub_apee_entries WHERE tenant_id = ? AND establishment_id = ? AND apee_year_id = ? AND id > ?';
        $params = [$tenantId, $establishmentId, $yearId, $afterId ?? ''];
        if ($direction !== null) {
            $sql .= ' AND direction = ?';
            $params[] = $direction;
        }

        return $this->all($sql . ' ORDER BY id LIMIT ' . max(1, $limit), $params);
    }

    /** @return list<array<string, mixed>> sommes par sens, catégorie et état */
    public function totals(string $tenantId, string $establishmentId, string $yearId): array
    {
        return $this->all('SELECT direction, category, entry_state, SUM(amount) AS total, COUNT(*) AS n FROM pub_apee_entries WHERE tenant_id = ? AND establishment_id = ? AND apee_year_id = ? GROUP BY direction, category, entry_state', [$tenantId, $establishmentId, $yearId]);
    }

    /** @return list<string> familles (élèves) qui ont contribué */
    public function contributingFamilies(string $tenantId, string $establishmentId, string $yearId): array
    {
        $query = $this->pdo()->prepare("SELECT DISTINCT family_ref FROM pub_apee_entries WHERE tenant_id = ? AND establishment_id = ? AND apee_year_id = ? AND direction = 'income' AND category = 'contribution' AND family_ref IS NOT NULL");
        $query->execute([$tenantId, $establishmentId, $yearId]);

        return array_values(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<array<string, mixed>> */
    public function receiptsOfFamily(string $tenantId, string $familyRef): array
    {
        return $this->all("SELECT e.*, y.year_label FROM pub_apee_entries e JOIN pub_apee_years y ON y.tenant_id = e.tenant_id AND y.id = e.apee_year_id WHERE e.tenant_id = ? AND e.family_ref = ? AND e.direction = 'income' ORDER BY e.entered_on DESC, e.id DESC", [$tenantId, $familyRef]);
    }

    public function updateEntry(string $tenantId, string $id, ?string $proofRef, string $state, ?string $documentId, ?string $number): void
    {
        $this->pdo()->prepare('UPDATE pub_apee_entries SET proof_ref = ?, entry_state = ?, receipt_document_id = ?, receipt_number = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$proofRef, $state, $documentId, $number, $tenantId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function exemptions(string $tenantId, string $establishmentId, string $yearId): array
    {
        return $this->all('SELECT * FROM pub_apee_exemptions WHERE tenant_id = ? AND establishment_id = ? AND apee_year_id = ? ORDER BY id', [$tenantId, $establishmentId, $yearId]);
    }

    /** @return list<array<string, mixed>> */
    public function supplies(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        return $this->all('SELECT * FROM pub_supply_receipts WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit), [$tenantId, $establishmentId, $afterId ?? '']);
    }

    /** @return array<string, mixed>|null */
    public function supply(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pub_supply_receipts WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function supplyLines(string $tenantId, string $receiptId): array
    {
        return $this->all('SELECT * FROM pub_supply_lines WHERE tenant_id = ? AND receipt_id = ? ORDER BY id', [$tenantId, $receiptId]);
    }

    public function setSupplyDocument(string $tenantId, string $id, string $documentId): void
    {
        $this->pdo()->prepare('UPDATE pub_supply_receipts SET document_id = ? WHERE tenant_id = ? AND id = ?')->execute([$documentId, $tenantId, $id]);
    }

    public static function newId(): string
    {
        return (string) new Ulid();
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
