<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Présence des enseignants et classes sans enseignant (pub_teacher_presence, pub_uncovered_classes), bornées par client et établissement. */
final class PdoTeacherPresence
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $staffId, string $day): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_teacher_presence WHERE tenant_id = ? AND establishment_id = ? AND staff_id = ? AND day_on = ?');
        $query->execute([$tenantId, $establishmentId, $staffId, $day]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByClientUuid(string $tenantId, string $establishmentId, string $clientUuid): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_teacher_presence WHERE tenant_id = ? AND establishment_id = ? AND client_uuid = ?');
        $query->execute([$tenantId, $establishmentId, $clientUuid]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $values */
    public function insert(string $tenantId, string $establishmentId, array $values): string
    {
        $id = (string) new Ulid();
        $row = ['id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId] + $values;
        $this->connections->pdo()->prepare(
            'INSERT INTO pub_teacher_presence (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
        )->execute(array_values($row));

        return $id;
    }

    /** @param array<string, mixed> $values colonnes de pub_teacher_presence (liste blanche côté appelant) */
    public function update(string $tenantId, string $id, array $values): void
    {
        $sets = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($values)));
        $this->connections->pdo()->prepare('UPDATE pub_teacher_presence SET ' . $sets . ' WHERE tenant_id = ? AND id = ?')
            ->execute([...array_values($values), $tenantId, $id]);
    }

    /** @return list<array<string, mixed>> déclarations de l'école à une date, par identifiant, après le curseur */
    public function ofDay(string $tenantId, string $establishmentId, string $day, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_teacher_presence WHERE tenant_id = ? AND establishment_id = ? AND day_on = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit),
        );
        $query->execute([$tenantId, $establishmentId, $day, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> déclarations entre deux jours inclus, par enseignant puis jour */
    public function between(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_teacher_presence WHERE tenant_id = ? AND establishment_id = ? AND day_on >= ? AND day_on <= ? ORDER BY staff_id, day_on',
        );
        $query->execute([$tenantId, $establishmentId, $from, $to]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Marque ou lève « classe sans enseignant » pour un jour.
     *
     * @return bool vrai si la classe vient d'être marquée (nouvelle alerte à lever)
     */
    public function setUncovered(string $tenantId, string $establishmentId, string $structureId, string $day, bool $uncovered): bool
    {
        $pdo = $this->connections->pdo();
        if (!$uncovered) {
            $pdo->prepare('DELETE FROM pub_uncovered_classes WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND day_on = ?')
                ->execute([$tenantId, $establishmentId, $structureId, $day]);

            return false;
        }
        try {
            $pdo->prepare('INSERT INTO pub_uncovered_classes (id, tenant_id, establishment_id, structure_id, day_on) VALUES (?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $establishmentId, $structureId, $day]);
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    public function countUncoveredBetween(string $tenantId, string $establishmentId, string $from, string $to): int
    {
        $query = $this->connections->pdo()->prepare('SELECT COUNT(*) FROM pub_uncovered_classes WHERE tenant_id = ? AND establishment_id = ? AND day_on >= ? AND day_on <= ?');
        $query->execute([$tenantId, $establishmentId, $from, $to]);

        return (int) $query->fetchColumn();
    }

    /** @return list<array<string, mixed>> classes sans enseignant à une date, après le curseur */
    public function uncoveredOn(string $tenantId, string $establishmentId, string $day, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_uncovered_classes WHERE tenant_id = ? AND establishment_id = ? AND day_on = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit),
        );
        $query->execute([$tenantId, $establishmentId, $day, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }
}
