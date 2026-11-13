<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Organisation (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'organisation';
    }

    protected function tables(): array
    {
        return [
            'tenants' => self::table('id name status plan created_at updated_at', ['tenant_column' => 'id']),
            'establishments' => self::table('id tenant_id name type branding modules_enabled created_at updated_at'),
            'academic_years' => self::table('id tenant_id establishment_id label starts_on ends_on status closed_at closed_by created_at updated_at'),
            'periods' => self::table('id tenant_id establishment_id academic_year_id kind ordinal starts_on ends_on created_at updated_at'),
            'org_units' => self::table('id tenant_id establishment_id parent_id type name created_at updated_at'),
            'pedagogical_structures' => self::table('id tenant_id establishment_id org_unit_id kind name capacity created_at updated_at'),
            'rooms' => self::table('id tenant_id establishment_id name capacity equipment created_at updated_at'),
            'authorizations' => self::table('id tenant_id establishment_id structure_id reference authority valid_from valid_to created_at updated_at'),
            'settings' => self::table('id scope_type tenant_id establishment_id setting_key value_json updated_by created_at updated_at', ['row' => self::withoutSecretSettings(...)]),
            'year_closures' => self::table('id tenant_id establishment_id academic_year_id next_year_id status approval_id requested_by requested_at decided_by closed_at check_report steps created_at updated_at'),
            'year_archives' => self::table('id tenant_id establishment_id academic_year_id closure_id snapshot created_at'),
        ];
    }

    /**
     * Les réglages dont la clé évoque un secret ne sont jamais exportés.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function withoutSecretSettings(array $row): ?array
    {
        return preg_match('/secret|password|passwd|token|api_?key|private/i', (string) $row['setting_key']) === 1 ? null : $row;
    }
}
