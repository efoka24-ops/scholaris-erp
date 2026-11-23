<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\OpenedEstablishments;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Lecture des profils ouverts (`se_establishment_profiles`) pour les tâches planifiées ; aucune donnée du profil n'est rendue. */
final class PdoOpenedEstablishments implements OpenedEstablishments
{
    public function __construct(private readonly Store $store)
    {
    }

    public function all(): array
    {
        $rows = $this->store->pdo()->query('SELECT tenant_id, establishment_id FROM se_establishment_profiles ORDER BY tenant_id, establishment_id');
        $out = [];
        foreach ($rows === false ? [] : $rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['tenant_id' => (string) $row['tenant_id'], 'establishment_id' => (string) $row['establishment_id']];
        }

        return $out;
    }
}
