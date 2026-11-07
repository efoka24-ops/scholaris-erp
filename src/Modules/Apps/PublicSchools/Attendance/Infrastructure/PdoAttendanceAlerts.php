<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Infrastructure;

use PDO;
use PDOException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/** Alertes d'assiduité déjà levées (pub_attendance_alerts) : une seule fois par sujet, période et niveau. */
final class PdoAttendanceAlerts
{
    public const REPEATED_ABSENCE = 'repeated_absence';
    public const NO_CALL = 'no_call';
    public const UNCOVERED_CLASS = 'uncovered_class';

    public const DIRECTOR = 'director';
    public const INSPECTION = 'inspection';

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return bool vrai si l'alerte vient d'être inscrite (à envoyer), faux si elle l'était déjà */
    public function raise(string $tenantId, string $establishmentId, string $kind, string $subjectId, string $periodKey, string $level, string $raisedOn, string $at): bool
    {
        try {
            $this->connections->pdo()->prepare(
                'INSERT INTO pub_attendance_alerts (id, tenant_id, establishment_id, kind, subject_id, period_key, level, recipients, raised_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $kind, $subjectId, $periodKey, $level, $raisedOn, $at]);
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    public function setRecipients(string $tenantId, string $establishmentId, string $kind, string $subjectId, string $periodKey, string $level, int $recipients): void
    {
        $this->connections->pdo()->prepare(
            'UPDATE pub_attendance_alerts SET recipients = ? WHERE tenant_id = ? AND establishment_id = ? AND kind = ? AND subject_id = ? AND period_key = ? AND level = ?',
        )->execute([$recipients, $tenantId, $establishmentId, $kind, $subjectId, $periodKey, $level]);
    }

    /**
     * @param list<string> $kinds
     * @param list<string> $levels
     * @return list<array<string, mixed>>
     */
    public function list(string $tenantId, string $establishmentId, array $kinds, array $levels, ?string $afterId, int $limit): array
    {
        if ($kinds === [] || $levels === []) {
            return [];
        }
        $query = $this->connections->pdo()->prepare(sprintf(
            'SELECT * FROM pub_attendance_alerts WHERE tenant_id = ? AND establishment_id = ? AND kind IN (%s) AND level IN (%s) AND id > ? ORDER BY id LIMIT %d',
            implode(', ', array_fill(0, count($kinds), '?')),
            implode(', ', array_fill(0, count($levels), '?')),
            max(1, $limit),
        ));
        $query->execute([$tenantId, $establishmentId, ...$kinds, ...$levels, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }
}
