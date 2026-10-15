<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Reporting (tenant:export) : rapports planifiés et journal d'exécution.
 * Liste blanche de colonnes ; ce module ne détient aucun secret.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'reporting';
    }

    protected function tables(): array
    {
        return [
            'report_schedules' => self::table('id tenant_id establishment_id name report_type frequency run_day recipient_ids status next_run_on last_run_at last_run_status created_by created_at updated_at'),
            'report_runs' => self::table('id tenant_id establishment_id schedule_id run_key trigger_kind status sent_count failed_count summary created_at'),
        ];
    }
}
