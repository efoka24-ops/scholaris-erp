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
     * @param string|null $subtype type d'établissement du secondaire (voir EstablishmentSubtypes), null ailleurs
     * @throws \Scholaris\Core\Http\HttpException 422 type ou sous-type inconnu, 409 nom déjà utilisé dans ce client
     */
    public function createEstablishment(string $tenantId, string $name, string $type, ?string $subtype = null): string;

    /** Renomme le client. @throws \Scholaris\Core\Http\HttpException 404 client introuvable, 422 nom invalide */
    public function renameTenant(string $tenantId, string $name): void;

    /** État du client : active, suspended ou archived (aucune donnée supprimée). */
    public function setTenantStatus(string $tenantId, string $status): void;

    /**
     * Tous les établissements du client, fermés compris (vue de la plateforme : nom, type, dates ; aucune donnée métier).
     *
     * @return list<array{id: string, name: string, type: string, closed_at: ?string, created_at: string}>
     */
    public function establishmentsOf(string $tenantId): array;

    /** @throws \Scholaris\Core\Http\HttpException 404 introuvable, 409 nom déjà utilisé, 422 nom invalide */
    public function renameEstablishment(string $tenantId, string $establishmentId, string $name): void;

    /** Ferme (true) ou rouvre (false) un établissement : fermé, il n'est plus proposé par l'annuaire ; ses données restent. */
    public function setEstablishmentClosed(string $tenantId, string $establishmentId, bool $closed): void;
}
