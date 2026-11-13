<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use InvalidArgumentException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Domain\EffectiveSetting;
use Scholaris\Modules\Socle\Organisation\Domain\SettingEntry;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoOrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoSettingsRepository;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;
use Throwable;

/**
 * Paramètres à quatre niveaux (RG-ORG-4, RG-ORG-5) : plateforme, client, établissement, unité interne (école, département ; D6).
 * Le plus spécifique l'emporte : unité, puis ses unités parentes, puis établissement, client, plateforme. La valeur effective dit
 * d'où elle vient et quelles valeurs elle masque. Toute modification est écrite dans le journal d'audit du client (qui, quoi, avant/après).
 * Sans unité demandée, le comportement est celui des trois niveaux historiques.
 */
final class SettingsService implements OrgUnitSettingsReader, \Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter
{
    public const KEY_FORMAT = '/^[a-z][a-z0-9_.-]{0,99}$/';
    private const MAX_VALUE_BYTES = 65536;

    public function __construct(
        private readonly PdoSettingsRepository $settings,
        private readonly Repositories $repositories,
        private readonly AuditLog $audit,
        private readonly OrgClock $clock,
        private readonly ConnectionFactory $connections,
        private readonly PdoOrgUnitTree $tree,
    ) {
    }

    public function get(SettingScope $scope, string $tenantId, ?string $establishmentId, string $key): ?SettingEntry
    {
        $this->assertTarget($scope, $tenantId, $establishmentId);

        return $this->settings->find($scope, $tenantId, $establishmentId, $key);
    }

    /** @return list<SettingEntry> */
    public function list(SettingScope $scope, string $tenantId, ?string $establishmentId): array
    {
        $this->assertTarget($scope, $tenantId, $establishmentId);

        return $this->settings->list($scope, $tenantId, $establishmentId);
    }

    public function getForOrgUnit(string $tenantId, string $establishmentId, string $orgUnitId, string $key): ?SettingEntry
    {
        $this->assertUnit($tenantId, $establishmentId, $orgUnitId);

        return $this->settings->findForUnit($tenantId, $establishmentId, $orgUnitId, $key);
    }

    /** @return list<SettingEntry> */
    public function listForOrgUnit(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        $this->assertUnit($tenantId, $establishmentId, $orgUnitId);

        return $this->settings->listForUnit($tenantId, $establishmentId, $orgUnitId);
    }

    public function set(
        SettingScope $scope,
        string $tenantId,
        ?string $establishmentId,
        string $key,
        mixed $value,
        string $actorId,
        ?string $ip = null,
        ?string $device = null,
    ): SettingEntry {
        $this->assertWritable($key, $value);
        $this->assertTarget($scope, $tenantId, $establishmentId);

        $before = $this->settings->find($scope, $tenantId, $establishmentId, $key);
        $now = $this->clock->now();
        $objectId = $scope->value . ':' . match ($scope) {
            SettingScope::Platform => 'platform',
            SettingScope::Tenant => $tenantId,
            default => (string) $establishmentId,
        } . ':' . $key;

        $this->transactional(function () use ($scope, $tenantId, $establishmentId, $key, $value, $actorId, $now, $before, $objectId, $ip, $device): void {
            $this->settings->save($scope, $tenantId, $establishmentId, $key, $value, $actorId, $now);
            $this->trace($tenantId, $actorId, $objectId, $scope, $before, $value, $ip, $device);
        });

        return new SettingEntry($scope, $key, $value, $actorId, $now);
    }

    /** Définit la valeur à la portée d'une unité interne de l'établissement (D6) ; tracé dans l'audit. */
    public function setForOrgUnit(
        string $tenantId,
        string $establishmentId,
        string $orgUnitId,
        string $key,
        mixed $value,
        string $actorId,
        ?string $ip = null,
        ?string $device = null,
    ): SettingEntry {
        $this->assertWritable($key, $value);
        $this->assertUnit($tenantId, $establishmentId, $orgUnitId);

        $before = $this->settings->findForUnit($tenantId, $establishmentId, $orgUnitId, $key);
        $now = $this->clock->now();
        $objectId = SettingScope::OrgUnit->value . ':' . $orgUnitId . ':' . $key;

        $this->transactional(function () use ($tenantId, $establishmentId, $orgUnitId, $key, $value, $actorId, $now, $before, $objectId, $ip, $device): void {
            $this->settings->saveForUnit($tenantId, $establishmentId, $orgUnitId, $key, $value, $actorId, $now);
            $this->trace($tenantId, $actorId, $objectId, SettingScope::OrgUnit, $before, $value, $ip, $device, $orgUnitId);
        });

        return new SettingEntry(SettingScope::OrgUnit, $key, $value, $actorId, $now, $orgUnitId);
    }

    public function presetIfAbsent(string $tenantId, string $establishmentId, string $key, mixed $value, string $actorId): bool
    {
        if ($this->get(SettingScope::Establishment, $tenantId, $establishmentId, $key) !== null) {
            return false;
        }
        $this->set(SettingScope::Establishment, $tenantId, $establishmentId, $key, $value, $actorId);

        return true;
    }

    public function effective(string $tenantId, ?string $establishmentId, string $key, ?string $orgUnitId = null): ?EffectiveSetting
    {
        $chain = [];
        foreach ($this->levels($tenantId, $establishmentId, $orgUnitId) as [$scope, $id]) {
            $entry = $scope === SettingScope::OrgUnit
                ? $this->settings->findForUnit($tenantId, (string) $establishmentId, (string) $id, $key)
                : $this->settings->find($scope, $tenantId, $id, $key);
            if ($entry !== null) {
                $chain[] = $entry;
            }
        }

        return $this->build($key, $chain);
    }

    /** @return list<EffectiveSetting> valeurs effectives de toutes les clés connues à l'un des niveaux, triées par clé */
    public function effectiveAll(string $tenantId, ?string $establishmentId, ?string $orgUnitId = null): array
    {
        $chains = [];
        foreach ($this->levels($tenantId, $establishmentId, $orgUnitId) as [$scope, $id]) {
            $entries = $scope === SettingScope::OrgUnit
                ? $this->settings->listForUnit($tenantId, (string) $establishmentId, (string) $id)
                : $this->settings->list($scope, $tenantId, $id);
            foreach ($entries as $entry) {
                $chains[$entry->key][] = $entry;
            }
        }
        ksort($chains);

        $result = [];
        foreach ($chains as $key => $chain) {
            $effective = $this->build((string) $key, $chain);
            if ($effective !== null) {
                $result[] = $effective;
            }
        }

        return $result;
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        $effective = $this->effective($tenantId, $establishmentId, $key);

        return $effective === null ? $default : $effective->value;
    }

    public function valueForOrgUnit(string $tenantId, string $establishmentId, ?string $orgUnitId, string $key, mixed $default = null): mixed
    {
        $effective = $this->effective($tenantId, $establishmentId, $key, $orgUnitId);

        return $effective === null ? $default : $effective->value;
    }

    /** @return list<array{0: SettingScope, 1: ?string}> du plus spécifique au plus général */
    private function levels(string $tenantId, ?string $establishmentId, ?string $orgUnitId = null): array
    {
        $levels = [];
        if ($orgUnitId !== null) {
            if ($establishmentId === null) {
                throw HttpException::notFound('Unité introuvable dans cet établissement.');
            }
            $this->assertUnit($tenantId, $establishmentId, $orgUnitId);
            foreach ([$orgUnitId, ...$this->tree->ancestorsOf($tenantId, $establishmentId, $orgUnitId)] as $unitId) {
                $levels[] = [SettingScope::OrgUnit, $unitId];
            }
        }
        if ($establishmentId !== null) {
            $this->assertTarget(SettingScope::Establishment, $tenantId, $establishmentId);
            $levels[] = [SettingScope::Establishment, $establishmentId];
        }
        $levels[] = [SettingScope::Tenant, null];
        $levels[] = [SettingScope::Platform, null];

        return $levels;
    }

    /** @param list<SettingEntry> $chain du plus spécifique au plus général */
    private function build(string $key, array $chain): ?EffectiveSetting
    {
        if ($chain === []) {
            return null;
        }
        $first = $chain[0];

        return new EffectiveSetting($key, $first->value, $first->scope, array_slice($chain, 1), $first->orgUnitId);
    }

    private function assertWritable(string $key, mixed $value): void
    {
        if (preg_match(self::KEY_FORMAT, $key) !== 1) {
            throw new InvalidArgumentException('La clé du paramètre est invalide.');
        }
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException('La valeur du paramètre est trop volumineuse.');
        }
    }

    /** Un établissement n'est jamais pris sur parole : il doit appartenir au client. La portée org_unit passe par les méthodes *ForOrgUnit. */
    private function assertTarget(SettingScope $scope, string $tenantId, ?string $establishmentId): void
    {
        if ($scope === SettingScope::OrgUnit) {
            throw new InvalidArgumentException('La portée org_unit exige une unité : utilisez les méthodes *ForOrgUnit.');
        }
        if ($scope !== SettingScope::Establishment) {
            return;
        }
        if ($establishmentId === null || $this->repositories->explicit('establishments', $tenantId)->find($establishmentId) === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }
    }

    /** L'unité doit appartenir à l'établissement du client (sinon 404 : son existence n'est pas révélée). */
    private function assertUnit(string $tenantId, string $establishmentId, string $orgUnitId): void
    {
        $this->assertTarget(SettingScope::Establishment, $tenantId, $establishmentId);
        if ($this->repositories->explicit('org_units', $tenantId, $establishmentId)->find($orgUnitId) === null) {
            throw HttpException::notFound('Unité introuvable dans cet établissement.');
        }
    }

    private function trace(string $tenantId, string $actorId, string $objectId, SettingScope $scope, ?SettingEntry $before, mixed $value, ?string $ip, ?string $device, ?string $orgUnitId = null): void
    {
        $unit = $orgUnitId === null ? [] : ['org_unit_id' => $orgUnitId];
        $this->audit->append(
            $tenantId,
            $actorId,
            'setting.updated',
            'setting',
            $objectId,
            $before === null ? null : ['scope' => $scope->value] + $unit + ['value' => $before->value],
            ['scope' => $scope->value] + $unit + ['value' => $value],
            $ip,
            $device,
        );
    }

    /** @param callable(): void $write */
    private function transactional(callable $write): void
    {
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $write();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
