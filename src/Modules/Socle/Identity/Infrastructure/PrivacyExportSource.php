<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Identity (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'identity';
    }

    protected function tables(): array
    {
        return [
            'users' => self::table('id tenant_id person_id phone email locale status two_factor_confirmed_at last_login_at created_at updated_at'),
            'role_assignments' => self::table('id tenant_id person_id role scope_type scope_id establishment_id starts_at ends_at delegated_by created_by created_at'),
            'devices' => self::table('id tenant_id user_id name location ip last_seen_at trusted_until revoked_at created_at'),
            'service_accounts' => self::table('id tenant_id name scopes key_prefix rotated_at revoked_at created_at'),
        ];
    }
}
