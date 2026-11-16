<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\EstablishmentPersonnel;
use Scholaris\Modules\Socle\Identity\Domain\Role;

/** Personnel d'un établissement : affectations de rôle en vigueur, hors rôles d'apprenant, de famille, de représentation et de contrôle externe. */
final class PdoEstablishmentPersonnel extends PdoRepository implements EstablishmentPersonnel
{
    /** Rôles qui ne font pas d'une personne un membre du personnel de l'établissement. */
    public const NOT_PERSONNEL = [
        'apprenant', 'parent', 'sec_eleve', 'sec_parent', 'sec_delegue_classe', 'sec_bureau_apee', 'pub_apee_board', 'pub_school_council',
        'higher_tutelle', 'sec_inspecteur', 'pub_inspection_general', 'pub_district_inspector', 'pub_inspection_clerk',
        ...Role::PLATFORM_ROLES,
    ];

    public function __construct(ConnectionFactory $connections, private readonly Moment $moment)
    {
        parent::__construct($connections);
    }

    public function personnelAmong(string $tenantId, string $establishmentId, array $personIds): array
    {
        $personIds = array_values(array_unique($personIds));
        if ($personIds === [] || $tenantId === Role::PLATFORM_TENANT) {
            return [];
        }
        $now = $this->moment->iso();
        $rows = $this->rows(
            'SELECT DISTINCT person_id FROM role_assignments
             WHERE tenant_id = ? AND person_id IN (' . implode(', ', array_fill(0, count($personIds), '?')) . ')
               AND role NOT IN (' . implode(', ', array_fill(0, count(self::NOT_PERSONNEL), '?')) . ')
               AND (scope_type = ? OR establishment_id = ?) AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY person_id',
            [$tenantId, ...$personIds, ...self::NOT_PERSONNEL, 'tenant', $establishmentId, $now, $now],
        );

        return array_map(static fn (array $row): string => (string) $row['person_id'], $rows);
    }
}
