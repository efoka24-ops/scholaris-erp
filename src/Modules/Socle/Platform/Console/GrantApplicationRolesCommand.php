<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Console;

use Scholaris\Modules\Socle\Platform\Application\ApplicationAdministratorRoles;

/**
 * `platform:grant-application-roles [<id du client>]` (IPES-ADMIN) : rattrapage idempotent des clients acceptés avant la correction.
 * Chaque administrateur de client (super_admin) qui n'exerce encore aucun rôle d'une application ouverte reçoit les rôles de
 * direction de son manifeste (IPES : directeur académique et administrateur fonctionnel). Relancer ne crée rien de plus ; les
 * autres utilisateurs ne sont jamais touchés. À lancer une fois après le déploiement.
 */
final class GrantApplicationRolesCommand
{
    public const NAME = 'platform:grant-application-roles';
    private const ACTOR = 'console';

    public function __construct(private readonly ApplicationAdministratorRoles $roles)
    {
    }

    /** @param list<string> $args */
    public function run(array $args = []): int
    {
        $tenants = isset($args[0]) && $args[0] !== '' ? [$args[0]] : $this->roles->tenants();
        $count = 0;
        foreach ($tenants as $tenantId) {
            foreach ($this->roles->grant($tenantId, self::ACTOR) as $assignment) {
                ++$count;
                echo sprintf("  %s : %s -> %s (%s)\n", $tenantId, $assignment['person_id'], $assignment['role'], $assignment['establishment_id'] ?? 'client');
            }
        }
        echo sprintf("%d client(s) examiné(s), %d rôle(s) attribué(s).\n", count($tenants), $count);

        return 0;
    }
}
