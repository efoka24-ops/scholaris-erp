<?php

declare(strict_types=1);

namespace Scholaris\Core\Numbering;

use LogicException;
use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * Numérotation continue par client, établissement, type et année (reçus, documents officiels).
 *
 * `next()` doit être appelé DANS la transaction qui crée l'objet numéroté : l'UPDATE verrouille la
 * ligne jusqu'au commit (les appels concurrents s'enchaînent) et, si la transaction échoue, le numéro
 * est annulé avec elle. Il n'y a donc ni trou ni doublon.
 */
final class SequenceService
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @param callable(PDO): mixed $work */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function next(string $tenantId, string $establishmentId, string $kind, int $year): int
    {
        $pdo = $this->connections->pdo();
        if (!$pdo->inTransaction()) {
            throw new LogicException('SequenceService::next() doit être appelé dans une transaction.');
        }

        $keys = [$tenantId, $establishmentId, $kind, $year];
        $where = 'tenant_id = ? AND establishment_id = ? AND kind = ? AND year = ?';

        $update = $pdo->prepare("UPDATE number_sequences SET current_value = current_value + 1 WHERE $where");
        $update->execute($keys);

        if ($update->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO number_sequences (tenant_id, establishment_id, kind, year, current_value) VALUES (?, ?, ?, ?, 1)')
                    ->execute($keys);

                return 1;
            } catch (PDOException) {
                // Une transaction concurrente vient de créer la ligne : on incrémente.
                $update->execute($keys);
            }
        }

        $select = $pdo->prepare("SELECT current_value FROM number_sequences WHERE $where");
        $select->execute($keys);

        return (int) $select->fetchColumn();
    }

    public static function format(string $prefix, int $year, int $number, int $width = 6): string
    {
        return sprintf('%s-%d-%0' . $width . 'd', $prefix, $year, $number);
    }
}
