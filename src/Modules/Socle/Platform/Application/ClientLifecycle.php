<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Cycle de vie d'un client vu par la plateforme : fiche (nom, contact, ville, région), création directe sans demande,
 * suspension (toutes ses applications ouvertes passent en lecture seule), réactivation, archivage (applications fermées).
 * Aucune suppression physique : les données du client restent exportables (`tenant:export`). Tout est journalisé.
 */
final class ClientLifecycle
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const ARCHIVED = 'archived';
    public const STATES = [self::ACTIVE, self::SUSPENDED, self::ARCHIVED];
    /** Préfixe du motif posé sur les applications suspendues ou fermées par une décision portant sur le client entier. */
    public const MARKER = '[client] ';

    public function __construct(
        private readonly PlatformDb $db,
        private readonly ClientProvisioning $clients,
        private readonly EntitlementService $entitlements,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> clients (archivés seulement si $state = archived), avec fiche et applications */
    public function list(?string $state): array
    {
        $items = [];
        foreach ($this->clients->tenants() as $tenant) {
            $client = $this->present($tenant);
            if ($state === null ? $client['state'] !== self::ARCHIVED : $client['state'] === $state) {
                $items[] = $client + ['applications' => $this->entitlements->forTenant($tenant['id'])];
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId): array
    {
        return $this->present($this->tenant($tenantId));
    }

    /**
     * Création directe d'un client (sans demande d'accès), avec son premier établissement si un type est donné.
     *
     * @param array{name: string, contact_name: string, contact_email: ?string, contact_phone: ?string, city: ?string, region: ?string, locale: string, establishment_type: ?string} $data
     * @return array<string, mixed>
     */
    public function create(array $data, string $actorId, ?string $ip): array
    {
        $tenantId = $this->clients->createTenant($data['name']);
        $establishmentId = $data['establishment_type'] === null ? null : $this->clients->createEstablishment($tenantId, $data['name'], $data['establishment_type']);
        $now = $this->clock->iso();
        $this->db->insert('platform_clients', [
            'tenant_id' => $tenantId,
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'locale' => $data['locale'],
            'access_request_id' => null,
            'client_state' => self::ACTIVE,
            'city' => $data['city'],
            'region' => $data['region'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record($tenantId, $actorId, 'platform.client_created', 'tenant', $tenantId, ['name' => $data['name'], 'establishment_id' => $establishmentId], $ip);

        return $this->show($tenantId);
    }

    /**
     * @param array<string, string|null> $changes name, contact_name, contact_email, contact_phone, city, region, locale
     * @return array<string, mixed>
     */
    public function update(string $tenantId, array $changes, string $actorId, ?string $ip): array
    {
        $this->assertNotArchived($this->show($tenantId));
        if (isset($changes['name'])) {
            $this->clients->renameTenant($tenantId, $changes['name']);
        }
        $profile = array_diff_key($changes, ['name' => true]);
        if ($profile !== []) {
            $this->ensureProfile($tenantId);
            $this->db->update('platform_clients', $profile + ['updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId]);
        }
        $this->audit->record($tenantId, $actorId, 'platform.client_updated', 'tenant', $tenantId, ['fields' => array_keys($changes)], $ip);

        return $this->show($tenantId);
    }

    /** @return array<string, mixed> */
    public function suspend(string $tenantId, string $reason, string $actorId, ?string $ip): array
    {
        $client = $this->show($tenantId);
        if ($client['state'] !== self::ACTIVE) {
            throw HttpException::conflict('Seul un client actif peut être suspendu.');
        }
        $suspended = [];
        foreach ($this->entitlements->forTenant($tenantId) as $app) {
            if (in_array($app['state'], [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true)) {
                $this->entitlements->decide($tenantId, (string) $app['app_key'], 'suspend', self::MARKER . $reason, null, $actorId, $ip);
                $suspended[] = (string) $app['app_key'];
            }
        }

        return $this->transition($tenantId, self::SUSPENDED, $reason, $actorId, $ip, 'platform.client_suspended', ['applications' => $suspended]);
    }

    /**
     * Réactive le client et les seules applications suspendues par la suspension du client.
     *
     * @return array<string, mixed>
     */
    public function reactivate(string $tenantId, ?string $reason, string $actorId, ?string $ip): array
    {
        $client = $this->show($tenantId);
        if ($client['state'] !== self::SUSPENDED) {
            throw HttpException::conflict('Seul un client suspendu peut être réactivé.');
        }
        $reactivated = [];
        foreach ($this->entitlements->forTenant($tenantId) as $app) {
            if ($app['state'] === ApplicationAccess::SUSPENDED && str_starts_with((string) $app['state_reason'], self::MARKER)) {
                $this->entitlements->decide($tenantId, (string) $app['app_key'], 'reactivate', $reason, null, $actorId, $ip);
                $reactivated[] = (string) $app['app_key'];
            }
        }

        return $this->transition($tenantId, self::ACTIVE, $reason, $actorId, $ip, 'platform.client_reactivated', ['applications' => $reactivated]);
    }

    /**
     * Archive un client suspendu : ses applications sont fermées, ses données conservées.
     *
     * @return array<string, mixed>
     */
    public function archive(string $tenantId, string $reason, string $actorId, ?string $ip): array
    {
        $client = $this->show($tenantId);
        if ($client['state'] !== self::SUSPENDED) {
            throw HttpException::conflict('Suspendez d\'abord le client : seul un client suspendu peut être archivé.');
        }
        foreach ($this->entitlements->forTenant($tenantId) as $app) {
            if ($app['state'] !== ApplicationAccess::CLOSED) {
                $this->entitlements->decide($tenantId, (string) $app['app_key'], 'close', self::MARKER . $reason, null, $actorId, $ip);
            }
        }

        return $this->transition($tenantId, self::ARCHIVED, $reason, $actorId, $ip, 'platform.client_archived', []);
    }

    /** @return array{id: string, name: string, status: string, created_at: string} */
    public function tenant(string $tenantId): array
    {
        foreach ($this->clients->tenants() as $tenant) {
            if ($tenant['id'] === $tenantId) {
                return $tenant;
            }
        }

        throw HttpException::notFound('Client introuvable.');
    }

    /** @param array<string, mixed> $client */
    public function assertNotArchived(array $client): void
    {
        if ($client['state'] === self::ARCHIVED) {
            throw HttpException::conflict('Ce client est archivé : aucune modification possible.');
        }
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function transition(string $tenantId, string $state, ?string $reason, string $actorId, ?string $ip, string $action, array $details): array
    {
        $this->ensureProfile($tenantId);
        $now = $this->clock->iso();
        $this->db->update('platform_clients', [
            'client_state' => $state,
            'state_reason' => $reason,
            'suspended_at' => $state === self::SUSPENDED ? $now : null,
            'archived_at' => $state === self::ARCHIVED ? $now : null,
            'updated_at' => $now,
        ], ['tenant_id' => $tenantId]);
        $this->clients->setTenantStatus($tenantId, $state);
        $this->audit->record($tenantId, $actorId, $action, 'tenant', $tenantId, ['reason' => $reason] + $details, $ip);

        return $this->show($tenantId);
    }

    /** Un client créé avant le back-office (console) n'a pas encore de fiche : elle est créée vide. */
    private function ensureProfile(string $tenantId): void
    {
        if ($this->db->one('SELECT tenant_id FROM platform_clients WHERE tenant_id = ?', [$tenantId]) !== null) {
            return;
        }
        $now = $this->clock->iso();
        $this->db->insert('platform_clients', [
            'tenant_id' => $tenantId, 'contact_name' => '', 'contact_email' => null, 'contact_phone' => null, 'locale' => 'fr',
            'access_request_id' => null, 'client_state' => self::ACTIVE, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * @param array{id: string, name: string, status: string, created_at: string} $tenant
     * @return array<string, mixed>
     */
    private function present(array $tenant): array
    {
        $profile = $this->db->one('SELECT * FROM platform_clients WHERE tenant_id = ?', [$tenant['id']]) ?? [];
        $state = (string) ($profile['client_state'] ?? (in_array($tenant['status'], self::STATES, true) ? $tenant['status'] : self::ACTIVE));

        return [
            'id' => $tenant['id'],
            'name' => $tenant['name'],
            'status' => $tenant['status'],
            'state' => $state,
            'state_reason' => $profile['state_reason'] ?? null,
            'suspended_at' => $profile['suspended_at'] ?? null,
            'archived_at' => $profile['archived_at'] ?? null,
            'contact' => [
                'name' => ($profile['contact_name'] ?? '') === '' ? null : (string) $profile['contact_name'],
                'email' => $profile['contact_email'] ?? null,
                'phone' => $profile['contact_phone'] ?? null,
            ],
            'city' => $profile['city'] ?? null,
            'region' => $profile['region'] ?? null,
            'locale' => (string) ($profile['locale'] ?? 'fr'),
            'access_request_id' => $profile['access_request_id'] ?? null,
            'created_at' => $tenant['created_at'],
        ];
    }
}
