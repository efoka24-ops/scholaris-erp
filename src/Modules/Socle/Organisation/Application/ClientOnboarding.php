<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentSubtypes;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleActivator;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\ModuleCatalog;
use Scholaris\Modules\Socle\Organisation\Infrastructure\EstablishmentMapper;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Création de clients et d'établissements pour la plateforme, et activation des modules requis par une application. */
final class ClientOnboarding implements ClientProvisioning, ModuleActivator
{
    private const PLATFORM_TENANT = 'platform';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EstablishmentService $establishments,
        private readonly Repositories $repositories,
        private readonly OrgClock $clock,
    ) {
    }

    public function createTenant(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw HttpException::validation(['name' => ['Nom de client invalide.']]);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->connections->pdo()
            ->prepare('INSERT INTO tenants (id, name, status, plan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, 'active', 'platform', $now, $now]);

        return $id;
    }

    public function tenantExists(string $tenantId): bool
    {
        if ($tenantId === self::PLATFORM_TENANT) {
            return false;
        }
        $statement = $this->connections->pdo()->prepare('SELECT 1 FROM tenants WHERE id = ?');
        $statement->execute([$tenantId]);

        return $statement->fetchColumn() !== false;
    }

    public function tenants(): array
    {
        $statement = $this->connections->pdo()->prepare('SELECT id, name, status, created_at FROM tenants WHERE id <> ? ORDER BY created_at, id');
        $statement->execute([self::PLATFORM_TENANT]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'status' => (string) $row['status'], 'created_at' => (string) $row['created_at']];
        }

        return $rows;
    }

    public function createEstablishment(string $tenantId, string $name, string $type, ?string $subtype = null): string
    {
        $kind = EstablishmentType::tryFrom($type) ?? throw HttpException::validation(['type' => ['Type d\'établissement inconnu.']]);
        if ($subtype !== null && !in_array($subtype, EstablishmentSubtypes::allowedFor($kind->value), true)) {
            throw HttpException::validation(['establishment_subtype' => ['Type d\'établissement inconnu pour ce niveau.']]);
        }
        if (!$this->tenantExists($tenantId)) {
            throw HttpException::notFound('Client introuvable.');
        }

        return (string) $this->establishments->create($tenantId, trim($name), $kind, $subtype)['id'];
    }

    public function renameTenant(string $tenantId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw HttpException::validation(['name' => ['Nom de client invalide.']]);
        }
        $this->updateTenant($tenantId, 'name', $name);
    }

    public function setTenantStatus(string $tenantId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'archived'], true)) {
            throw HttpException::validation(['status' => ['État de client inconnu.']]);
        }
        $this->updateTenant($tenantId, 'status', $status);
    }

    public function establishmentsOf(string $tenantId): array
    {
        if (!$this->tenantExists($tenantId)) {
            throw HttpException::notFound('Client introuvable.');
        }
        $rows = [];
        foreach ($this->repositories->explicit('establishments', $tenantId)->all() as $row) {
            $rows[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'establishment_subtype' => isset($row['establishment_subtype']) ? (string) $row['establishment_subtype'] : null,
                'closed_at' => isset($row['closed_at']) ? (string) $row['closed_at'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    public function renameEstablishment(string $tenantId, string $establishmentId, string $name): void
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw HttpException::validation(['name' => ['Nom d\'établissement invalide (2 à 160 caractères).']]);
        }
        $this->repositories->explicit('establishments', $tenantId)->findOrFail($establishmentId);
        $this->establishments->rename($tenantId, $establishmentId, $name);
    }

    public function setEstablishmentClosed(string $tenantId, string $establishmentId, bool $closed): void
    {
        $this->repositories->explicit('establishments', $tenantId)->findOrFail($establishmentId);
        $now = $this->clock->now();
        $this->connections->pdo()
            ->prepare('UPDATE establishments SET closed_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$closed ? $now : null, $now, $tenantId, $establishmentId]);
    }

    private function updateTenant(string $tenantId, string $column, string $value): void
    {
        if (!$this->tenantExists($tenantId)) {
            throw HttpException::notFound('Client introuvable.');
        }
        $sql = $column === 'name' ? 'UPDATE tenants SET name = ?, updated_at = ? WHERE id = ?' : 'UPDATE tenants SET status = ?, updated_at = ? WHERE id = ?';
        $this->connections->pdo()->prepare($sql)->execute([$value, $this->clock->now(), $tenantId]);
    }

    public function enable(string $tenantId, string $establishmentId, array $modules): void
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->findOrFail($establishmentId);
        $current = array_values(array_filter(EstablishmentMapper::decode((string) $row['modules_enabled']), 'is_string'));
        $wanted = array_values(array_unique(array_merge($current, array_filter($modules, [ModuleCatalog::class, 'isKnown']))));
        if (array_diff($wanted, $current) === []) {
            return;
        }
        $this->establishments->setModules($tenantId, $establishmentId, $wanted);
    }
}
