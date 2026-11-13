<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Infrastructure\PrivacyDb;

/**
 * Rétention des données d'Audit uniquement : demandes de personnes closes et approbations décidées sont anonymisées
 * après la durée paramétrée. Le journal chaîné (audit_log) n'est jamais modifié : sa vérification en dépend.
 */
final class RetentionService
{
    public const MASK = '[anonymisé]';

    public function __construct(
        private readonly PrivacyDb $db,
        private readonly PrivacySettings $settings,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    /** @return list<string> clients connus d'Audit */
    public function tenants(): array
    {
        $ids = [];
        foreach (['audit_log', 'data_subject_requests', 'approvals'] as $table) {
            foreach ($this->db->rows('SELECT DISTINCT tenant_id FROM ' . $table) as $row) {
                $ids[(string) $row['tenant_id']] = true;
            }
        }
        $list = array_keys($ids);
        sort($list);

        return $list;
    }

    /**
     * @return array{tenant_id: string, applied: bool, requests: int, approvals: int}
     */
    public function runForTenant(string $tenantId, bool $apply): array
    {
        $requestCutoff = $this->cutoff($this->settings->get($tenantId, 'requests_retention_months'));
        $approvalCutoff = $this->cutoff($this->settings->get($tenantId, 'approvals_retention_months'));

        $requestWhere = "tenant_id = ? AND status IN ('processed', 'refused') AND processed_at < ? AND (subject_person_id NOT LIKE 'anon-%' OR details IS NOT NULL OR resolution IS NOT NULL)";
        $approvalWhere = "tenant_id = ? AND status <> 'pending' AND decided_at < ? AND reason <> ?";

        $requests = (int) ($this->db->row('SELECT COUNT(*) AS n FROM data_subject_requests WHERE ' . $requestWhere, [$tenantId, $requestCutoff])['n'] ?? 0);
        $approvals = (int) ($this->db->row('SELECT COUNT(*) AS n FROM approvals WHERE ' . $approvalWhere, [$tenantId, $approvalCutoff, self::MASK])['n'] ?? 0);

        if ($apply && ($requests > 0 || $approvals > 0)) {
            foreach ($this->db->rows('SELECT id, subject_person_id FROM data_subject_requests WHERE ' . $requestWhere, [$tenantId, $requestCutoff]) as $row) {
                $this->db->exec(
                    'UPDATE data_subject_requests SET subject_person_id = ?, details = NULL, resolution = NULL, refusal_reason = NULL WHERE tenant_id = ? AND id = ?',
                    [AuditDataSources::pseudonym($tenantId, (string) $row['subject_person_id']), $tenantId, $row['id']],
                );
            }
            $this->db->exec('UPDATE approvals SET reason = ? WHERE ' . $approvalWhere, [self::MASK, $tenantId, $approvalCutoff, self::MASK]);
            $summary = ['requests' => $requests, 'approvals' => $approvals];
            $this->db->exec(
                'INSERT INTO retention_runs (id, tenant_id, applied, summary_json, run_at) VALUES (?, ?, ?, ?, ?)',
                [(string) new Ulid(), $tenantId, 1, json_encode($summary, JSON_THROW_ON_ERROR), $this->moment()],
            );
            $this->audit->append($tenantId, null, 'privacy.retention.applied', 'tenant', $tenantId, null, $summary);
        }

        return ['tenant_id' => $tenantId, 'applied' => $apply, 'requests' => $requests, 'approvals' => $approvals];
    }

    /** @return list<array{tenant_id: string, applied: bool, requests: int, approvals: int}> */
    public function run(?string $tenantId, bool $apply): array
    {
        $report = [];
        foreach ($tenantId !== null ? [$tenantId] : $this->tenants() as $id) {
            $report[] = $this->runForTenant($id, $apply);
        }

        return $report;
    }

    private function cutoff(int $months): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->modify('-' . $months . ' months')->format(DATE_ATOM);
    }

    private function moment(): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM);
    }
}
