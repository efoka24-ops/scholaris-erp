<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Territory\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/**
 * RG-TER-5 : une école créée par décret porte la référence de l'acte de création ; une école sans acte n'apparaît pas dans les
 * statistiques officielles. Lecture seule de la fiche, bornée au client.
 */
final class PdoOfficialSchools
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param list<string> $establishmentIds
     * @return array<string, bool> établissement => vrai si l'école a une référence d'acte de création
     */
    public function flags(string $tenantId, array $establishmentIds): array
    {
        if ($establishmentIds === []) {
            return [];
        }
        $query = $this->connections->pdo()->prepare(
            'SELECT establishment_id, creation_act_ref FROM pub_schools WHERE tenant_id = ? AND establishment_id IN (' . implode(', ', array_fill(0, count($establishmentIds), '?')) . ')',
        );
        $query->execute([$tenantId, ...$establishmentIds]);
        $flags = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $flags[(string) $row['establishment_id']] = is_string($row['creation_act_ref']) && trim($row['creation_act_ref']) !== '';
        }

        return $flags;
    }
}
