<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritorySettings;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;

final class TerritorySettingsService implements TerritorySettings
{
    /** Ordre de profondeur : plus le rang est grand, plus le niveau est proche de l'école. */
    private const ORDER = [
        self::NATIONAL => 0,
        self::REGION => 1,
        self::DEPARTMENT => 2,
        self::DISTRICT => 3,
        self::ESTABLISHMENT => 4,
    ];
    private const NATIONAL_ID = '';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly TerritoryDirectory $territories,
        private readonly SettingsService $settings,
        private readonly AuditLog $audit,
        private readonly OrgClock $clock,
    ) {
    }

    public function set(string $tenantId, ?string $territoryId, string $key, mixed $value, string $lockLevel, string $actorId): void
    {
        if (preg_match(SettingsService::KEY_FORMAT, $key) !== 1) {
            throw HttpException::validation(['key' => ['Clé de paramètre invalide.']]);
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 65536) {
            throw HttpException::validation(['value' => ['Valeur trop volumineuse.']]);
        }
        $at = $this->clock->now();
        [$level, $ancestors] = $this->resolve($tenantId, $territoryId, $at);
        if (!isset(self::ORDER[$lockLevel]) || self::ORDER[$lockLevel] < self::ORDER[$level]) {
            throw HttpException::validation(['lock_level' => ['Niveau de verrouillage invalide : il ne peut pas être plus haut que le niveau de l\'entrée.']]);
        }

        foreach ($ancestors as $ancestorId) {
            $entry = $this->entry($tenantId, $ancestorId, $key);
            if ($entry !== null && self::ORDER[$level] > self::ORDER[$entry['lock_level']]) {
                throw HttpException::forbidden(sprintf('Ce paramètre est verrouillé au niveau « %s » : le niveau « %s » ne peut pas le modifier.', $entry['lock_level'], $level));
            }
        }

        $id = $territoryId ?? self::NATIONAL_ID;
        $before = $this->entry($tenantId, $id, $key);
        $pdo = $this->connections->pdo();
        $update = $pdo->prepare('UPDATE territory_settings SET value_json = ?, lock_level = ?, updated_by = ?, updated_at = ? WHERE tenant_id = ? AND territory_id = ? AND setting_key = ?');
        $update->execute([$json, $lockLevel, $actorId, $at, $tenantId, $id, $key]);
        if ($update->rowCount() === 0) {
            $pdo->prepare('INSERT INTO territory_settings (id, tenant_id, territory_id, level_code, setting_key, value_json, lock_level, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $id, $level, $key, $json, $lockLevel, $actorId, $at, $at]);
        }
        $this->audit->append(
            $tenantId,
            $actorId,
            'territory_setting.updated',
            'territory_setting',
            ($territoryId ?? 'national') . ':' . $key,
            $before === null ? null : ['value' => $before['value'], 'lock_level' => $before['lock_level']],
            ['value' => $value, 'lock_level' => $lockLevel, 'level' => $level],
        );
    }

    public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed
    {
        $at = $this->clock->now();
        $chain = [[self::NATIONAL, self::NATIONAL_ID]];
        $territory = $this->territories->territoryOf($tenantId, $establishmentId, $at);
        if ($territory !== null) {
            foreach (array_reverse($this->territories->lineage($tenantId, $territory->id, $at)) as $info) {
                $chain[] = [$info->type, $info->id];
            }
        }

        $result = null;
        $found = false;
        $lockedAt = null;
        foreach ($chain as [$level, $id]) {
            $entry = $this->entry($tenantId, $id, $key);
            if ($entry === null || !isset(self::ORDER[$level]) || ($lockedAt !== null && self::ORDER[$level] > self::ORDER[$lockedAt])) {
                continue;
            }
            [$result, $found] = [$entry['value'], true];
            $lockedAt = $lockedAt === null || self::ORDER[$entry['lock_level']] < self::ORDER[$lockedAt] ? $entry['lock_level'] : $lockedAt;
        }
        $own = $this->settings->get(SettingScope::Establishment, $tenantId, $establishmentId, $key);
        if ($own !== null && ($lockedAt === null || self::ORDER[self::ESTABLISHMENT] <= self::ORDER[$lockedAt])) {
            return $own->value;
        }
        if ($found) {
            return $result;
        }
        $fallback = $this->settings->get(SettingScope::Tenant, $tenantId, null, $key) ?? $this->settings->get(SettingScope::Platform, $tenantId, null, $key);

        return $fallback === null ? $default : $fallback->value;
    }

    /**
     * @return array{0: string, 1: list<string>} niveau du territoire cible et identifiants des niveaux supérieurs (national compris)
     */
    private function resolve(string $tenantId, ?string $territoryId, string $at): array
    {
        if ($territoryId === null) {
            return [self::NATIONAL, []];
        }
        $lineage = $this->territories->lineage($tenantId, $territoryId, $at);
        if ($lineage === [] || !isset(self::ORDER[$lineage[0]->type])) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        $ancestors = [self::NATIONAL_ID];
        foreach (array_slice($lineage, 1) as $info) {
            $ancestors[] = $info->id;
        }

        return [$lineage[0]->type, $ancestors];
    }

    /** @return array{value: mixed, lock_level: string}|null */
    private function entry(string $tenantId, string $territoryId, string $key): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT value_json, lock_level FROM territory_settings WHERE tenant_id = ? AND territory_id = ? AND setting_key = ? LIMIT 1');
        $statement->execute([$tenantId, $territoryId, $key]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return ['value' => json_decode((string) $row['value_json'], true), 'lock_level' => (string) $row['lock_level']];
    }
}
