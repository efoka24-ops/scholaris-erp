<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;
use Scholaris\Modules\Socle\Audit\Contracts\TenantDataSource;
use Scholaris\Modules\Socle\Audit\Infrastructure\PrivacyDb;

/** Sources de données des tables propres à Audit, pour l'export d'un client et pour les demandes d'une personne. */
final class AuditDataSources implements TenantDataSource, SubjectDataSource
{
    public const PSEUDONYM_PREFIX = 'anon-';

    public function __construct(private readonly PrivacyDb $db)
    {
    }

    public function name(): string
    {
        return 'audit';
    }

    public function export(string $tenantId): array
    {
        $tables = [
            'audit_log' => 'SELECT * FROM audit_log WHERE tenant_id = ? ORDER BY seq',
            'approvals' => 'SELECT * FROM approvals WHERE tenant_id = ? ORDER BY id',
            'data_subject_requests' => 'SELECT * FROM data_subject_requests WHERE tenant_id = ? ORDER BY id',
            'privacy_settings' => 'SELECT * FROM privacy_settings WHERE tenant_id = ? ORDER BY setting_name',
            'retention_runs' => 'SELECT * FROM retention_runs WHERE tenant_id = ? ORDER BY id',
        ];
        $out = [];
        foreach ($tables as $table => $sql) {
            $out[$table] = $this->db->rows($sql, [$tenantId]);
        }

        return $out;
    }

    public function collect(string $tenantId, string $personId): array
    {
        return [
            'audit_log' => $this->db->rows(
                'SELECT seq, actor_id, action, object_type, object_id, occurred_at FROM audit_log WHERE tenant_id = ? AND (actor_id = ? OR (object_type = ? AND object_id = ?)) ORDER BY seq',
                [$tenantId, $personId, 'person', $personId],
            ),
            'data_subject_requests' => $this->db->rows(
                'SELECT id, kind, status, received_at, due_at, processed_at, refusal_reason, resolution FROM data_subject_requests WHERE tenant_id = ? AND subject_person_id = ? ORDER BY id',
                [$tenantId, $personId],
            ),
        ];
    }

    /**
     * Le journal chaîné n'est jamais modifié (intégrité de la chaîne, obligation de traçabilité). Les demandes de la personne sont
     * dépersonnalisées ; la demande d'effacement elle-même garde l'identifiant, preuve de la réponse apportée.
     */
    public function anonymize(string $tenantId, string $personId): int
    {
        return $this->db->exec(
            "UPDATE data_subject_requests SET subject_person_id = ?, details = NULL, resolution = NULL WHERE tenant_id = ? AND subject_person_id = ? AND kind <> 'erasure'",
            [self::pseudonym($tenantId, $personId), $tenantId, $personId],
        );
    }

    public static function pseudonym(string $tenantId, string $personId): string
    {
        return self::PSEUDONYM_PREFIX . substr(hash('sha256', $tenantId . '|' . $personId), 0, 12);
    }
}
