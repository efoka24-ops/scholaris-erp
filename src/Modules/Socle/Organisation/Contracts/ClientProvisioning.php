<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Intégration d'un nouveau client par la plateforme (acceptation d'une demande d'accès) et vue d'ensemble des clients
 * pour l'administrateur de plateforme. Fourni par Organisation ; le client technique `platform` n'est jamais listé.
 */
interface ClientProvisioning
{
    /** Crée un client actif ; renvoie son identifiant (ULID). */
    public function createTenant(string $name): string;

    public function tenantExists(string $tenantId): bool;

    /** @return list<array{id: string, name: string, status: string, created_at: string}> clients triés par date de création */
    public function tenants(): array;

    /**
     * Crée un établissement du type indiqué (nursery, primary, secondary, higher) ; renvoie son identifiant.
     *
     * @throws \Scholaris\Core\Http\HttpException 422 type inconnu, 409 nom déjà utilisé dans ce client
     */
    public function createEstablishment(string $tenantId, string $name, string $type): string;
}
