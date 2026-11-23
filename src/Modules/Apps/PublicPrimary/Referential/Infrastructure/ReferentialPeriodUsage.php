<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Referential\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUsage;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;

/**
 * Usages d'une période par le référentiel du primaire public : unités d'apprentissage (bornées par leurs propres dates). Le référentiel est propre au client : l'établissement est ignoré.
 * Fournisseur du contrat Organisation PeriodUsage : refuse la suppression d'une période utilisée et borne le déplacement
 * de ses dates. Chaque requête porte le client (et l'établissement) et renvoie n, first_on, last_on.
 */
final class ReferentialPeriodUsage implements PeriodUsage
{
    /** Libellé de l'usage => requête de comptage (paramètres : client, période). */
    private const QUERIES = [
        'unité(s) d\'apprentissage' => 'SELECT COUNT(*) AS n, MIN(starts_on) AS first_on, MAX(ends_on) AS last_on FROM pp_learning_units WHERE tenant_id = ? AND period_id = ?',
    ];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function uses(string $tenantId, string $establishmentId, string $periodId): array
    {
        $uses = [];
        foreach (self::QUERIES as $label => $sql) {
            $statement = $this->connections->pdo()->prepare($sql);
            $statement->execute([$tenantId, $periodId]);
            $row = (array) $statement->fetch(\PDO::FETCH_ASSOC);
            $count = (int) ($row['n'] ?? 0);
            if ($count > 0) {
                $uses[] = new PeriodUse($label, $count, self::day($row['first_on'] ?? null), self::day($row['last_on'] ?? null));
            }
        }

        return $uses;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
