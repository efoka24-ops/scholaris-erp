<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Tests\Support\AppTestCase;

/**
 * Base des tests du module Organisation : Kernel de test (SQLite en mémoire migrée), horloge figée et
 * doublure de PermissionChecker. Les établissements sont semés directement, avec des identifiants lisibles.
 */
abstract class OrganisationTestCase extends AppTestCase
{
    protected StubPermissions $permissions;
    protected FixedClock $clock;
    protected FixedRecentSecondFactor $secondFactor;

    /** @param array<string, mixed> $extra définitions supplémentaires (fournisseurs de clôture, doublures) */
    protected function bootOrganisation(array $extra = []): void
    {
        $this->permissions = new StubPermissions();
        $this->clock = new FixedClock();
        $this->secondFactor = new FixedRecentSecondFactor();
        $this->boot($extra + [
            PermissionChecker::class => $this->permissions,
            Clock::class => $this->clock,
            RecentSecondFactor::class => $this->secondFactor,
        ]);
    }

    /** Sème un établissement et donne à l'utilisateur l'accès au contexte (EstablishmentAccess). */
    protected function seedEstablishment(string $tenant, string $id, string $name, string $type = 'secondary', string ...$users): void
    {
        $now = '2026-10-08T09:00:00+01:00';
        $branding = ['accent' => '#F26B1D', 'logo' => null, 'initial' => mb_substr($name, 0, 1), 'headers' => [], 'signatories' => [], 'authorization_numbers' => []];
        $this->connections()->pdo()->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $id, $tenant, $name, $type,
            json_encode($branding, JSON_THROW_ON_ERROR),
            json_encode(['people', 'billing', 'messaging', 'documents', 'scheduling', 'attendance', 'assessment', 'staff', 'reporting'], JSON_THROW_ON_ERROR),
            $now, $now,
        ]);
        foreach ($users as $user) {
            $this->access->grant($user, $id);
        }
    }

    /** Accorde à un utilisateur les permissions du module sur tout son client. */
    protected function grantAll(string $user, string ...$permissions): void
    {
        foreach ($permissions ?: ['organisation.read', 'organisation.manage', 'settings.manage'] as $permission) {
            $this->permissions->grant($user, $permission);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>} réponse et corps JSON décodé
     */
    protected function api(string $method, string $path, string $user, string $tenant, ?string $establishment = null, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $user . ':' . $tenant];
        if ($establishment !== null) {
            $headers['X-Establishment-Id'] = $establishment;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = $this->call($method, '/api/v1' . $path, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        $raw = (string) $response->getBody();

        return [$response, $raw === '' ? [] : (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * Clôture complète d'une année : demande par « alice », approbation par « bob » (2FA récente). Renvoie la réponse d'approbation.
     *
     * @param array<string, mixed>|null $body
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected function closeYear(string $yearId, string $establishment, string $tenant = 't1', ?array $body = null): array
    {
        [$requested, $json] = $this->api('POST', '/academic-years/' . $yearId . '/close', 'alice', $tenant, $establishment, $body);
        if ($requested->getStatusCode() !== 202) {
            return [$requested, $json];
        }

        return $this->api('POST', '/academic-years/' . $yearId . '/close/approve', 'bob', $tenant, $establishment);
    }

    protected function code(string $method, string $path, string $user, string $tenant, ?string $establishment = null, ?array $body = null): int
    {
        return $this->api($method, $path, $user, $tenant, $establishment, $body)[0]->getStatusCode();
    }
}
