<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Core\Support\Ulid;
use Scholaris\Core\Tenancy\TenantTable;
use Scholaris\Modules\Socle\Organisation\Domain\SettingEntry;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;

/**
 * Table `settings` : niveau plateforme = tenant_id « platform » ; niveau client = establishment_id vide.
 * Toutes les lectures et écritures passent par TenantTable, donc bornées au client (ou au niveau plateforme).
 */
final class PdoSettingsRepository
{
    private const PLATFORM = 'platform';

    public function __construct(private readonly TableFactory $tables)
    {
    }

    public function find(SettingScope $scope, string $tenantId, ?string $establishmentId, string $key): ?SettingEntry
    {
        [$table, $where] = $this->target($scope, $tenantId, $establishmentId);
        $rows = $table->select($where + ['setting_key' => $key], 'id', 1);

        return $rows === [] ? null : $this->entry($scope, $rows[0]);
    }

    /** @return list<SettingEntry> triés par clé */
    public function list(SettingScope $scope, string $tenantId, ?string $establishmentId): array
    {
        [$table, $where] = $this->target($scope, $tenantId, $establishmentId);
        $entries = array_map(fn (array $row): SettingEntry => $this->entry($scope, $row), $table->select($where, 'setting_key', 10000));
        usort($entries, static fn (SettingEntry $a, SettingEntry $b): int => strcmp($a->key, $b->key));

        return $entries;
    }

    public function save(SettingScope $scope, string $tenantId, ?string $establishmentId, string $key, mixed $value, string $actorId, string $now): void
    {
        [$table, $where] = $this->target($scope, $tenantId, $establishmentId);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $changed = $table->update(
            ['value_json' => $json, 'updated_by' => $actorId, 'updated_at' => $now],
            $where + ['setting_key' => $key],
        );
        if ($changed > 0) {
            return;
        }

        $table->insert([
            'id' => (string) new Ulid(),
            'scope_type' => $scope->value,
            'setting_key' => $key,
            'value_json' => $json,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Portée `org_unit` (D6) : table org_unit_settings, bornée au client et à l'établissement. */
    public function findForUnit(string $tenantId, string $establishmentId, string $orgUnitId, string $key): ?SettingEntry
    {
        $rows = $this->unitTable($tenantId, $establishmentId)->select(['org_unit_id' => $orgUnitId, 'setting_key' => $key], 'id', 1);

        return $rows === [] ? null : $this->entry(SettingScope::OrgUnit, $rows[0]);
    }

    /** @return list<SettingEntry> triés par clé */
    public function listForUnit(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $entries = array_map(
            fn (array $row): SettingEntry => $this->entry(SettingScope::OrgUnit, $row),
            $this->unitTable($tenantId, $establishmentId)->select(['org_unit_id' => $orgUnitId], 'setting_key', 10000),
        );
        usort($entries, static fn (SettingEntry $a, SettingEntry $b): int => strcmp($a->key, $b->key));

        return $entries;
    }

    public function saveForUnit(string $tenantId, string $establishmentId, string $orgUnitId, string $key, mixed $value, string $actorId, string $now): void
    {
        $table = $this->unitTable($tenantId, $establishmentId);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $changed = $table->update(['value_json' => $json, 'updated_by' => $actorId, 'updated_at' => $now], ['org_unit_id' => $orgUnitId, 'setting_key' => $key]);
        if ($changed > 0) {
            return;
        }
        $table->insert([
            'id' => (string) new Ulid(),
            'scope_type' => SettingScope::OrgUnit->value,
            'org_unit_id' => $orgUnitId,
            'setting_key' => $key,
            'value_json' => $json,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function unitTable(string $tenantId, string $establishmentId): TenantTable
    {
        return $this->tables->explicit($tenantId, $establishmentId, 'org_unit_settings', true);
    }

    /** @return array{0: TenantTable, 1: array<string, scalar|null>} */
    private function target(SettingScope $scope, string $tenantId, ?string $establishmentId): array
    {
        return match ($scope) {
            SettingScope::OrgUnit => throw new \InvalidArgumentException('La portée org_unit se lit et s\'écrit par unité (findForUnit, saveForUnit).'),
            SettingScope::Platform => [
                $this->tables->explicit(self::PLATFORM, null, 'settings', false),
                ['scope_type' => 'platform', 'establishment_id' => ''],
            ],
            SettingScope::Tenant => [
                $this->tables->explicit($tenantId, null, 'settings', false),
                ['scope_type' => 'tenant', 'establishment_id' => ''],
            ],
            SettingScope::Establishment => [
                $this->tables->explicit($tenantId, $establishmentId, 'settings', true),
                ['scope_type' => 'establishment'],
            ],
        };
    }

    /** @param array<string, mixed> $row */
    private function entry(SettingScope $scope, array $row): SettingEntry
    {
        return new SettingEntry(
            $scope,
            (string) $row['setting_key'],
            json_decode((string) $row['value_json'], true),
            (string) $row['updated_by'],
            (string) $row['updated_at'],
            $scope === SettingScope::OrgUnit ? (string) $row['org_unit_id'] : null,
        );
    }
}
