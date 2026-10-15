<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Contrat sortant LOCAL : une source de données d'un client pour l'export complet (tenant:export).
 * Chaque module peut fournir une implémentation et l'enregistrer dans son `definitions.php` sous une clé
 * `privacy.tenant_data_source.<module>` (valeur : `\DI\get(MaSource::class)`), ou à l'exécution via
 * `Application\TenantDataSources::register()`. L'implémentation ne renvoie jamais de secret (hachages de mot de passe, jetons, clés).
 */
interface TenantDataSource
{
    /** Nom court et stable de la source (nom du module), utilisé comme dossier dans l'archive. */
    public function name(): string;

    /**
     * @return array<string, list<array<string, mixed>>> lignes exportables par nom de table ou de collection
     */
    public function export(string $tenantId): array;
}
