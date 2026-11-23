<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Modules\Socle\Identity\Contracts\ClientAdministrators;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * IPES-ADMIN : quand un client obtient une application (ouverture, demande d'accès acceptée, administrateur invité), ses
 * administrateurs (super_admin) reçoivent les rôles de direction déclarés par le manifeste (`administratorRoles`) dans chaque
 * établissement de la cible où ils n'exercent encore aucun rôle de l'application. Sans ce rôle, super_admin n'a que
 * `<application>.access` et chaque écran de l'application lui répond « Accès refusé ». Idempotent (rattrapage :
 * `platform:grant-application-roles`). L'attribution passe par Identity\Contracts\ClientAdministrators.
 */
final class ApplicationAdministratorRoles
{
    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly EstablishmentDirectory $establishments,
        private readonly ClientAdministrators $administrators,
    ) {
    }

    /**
     * Applications du client non fermées (ou la seule `$appKey`), pour tous ses administrateurs.
     *
     * @return list<array{app_key: string, person_id: string, role: string, establishment_id: string|null}> affectations créées
     */
    public function grant(string $tenantId, string $actorId, ?string $appKey = null): array
    {
        $sql = 'SELECT app_key FROM tenant_applications WHERE tenant_id = ? AND state <> ?';
        $params = [$tenantId, ApplicationAccess::CLOSED];
        if ($appKey !== null) {
            $sql .= ' AND app_key = ?';
            $params[] = $appKey;
        }
        $created = [];
        foreach ($this->db->all($sql . ' ORDER BY app_key', $params) as $row) {
            $key = (string) $row['app_key'];
            $manifest = $this->catalog->manifest($key);
            if ($manifest === null || $manifest->administratorRoles === []) {
                continue;
            }
            $targets = [];
            foreach ($this->establishments->forTenant($tenantId) as $establishment) {
                if ($establishment->type === $manifest->target) {
                    $targets[] = $establishment->id;
                }
            }
            if ($targets === []) {
                continue;
            }
            foreach ($this->administrators->grantApplicationRoles($tenantId, $manifest->administratorRoles, $key . '.', $targets, $actorId) as $assignment) {
                $created[] = ['app_key' => $key] + $assignment;
            }
        }

        return $created;
    }

    /** @return list<string> clients ayant au moins une application non fermée */
    public function tenants(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['tenant_id'],
            $this->db->all('SELECT DISTINCT tenant_id FROM tenant_applications WHERE state <> ? ORDER BY tenant_id', [ApplicationAccess::CLOSED]),
        );
    }
}
