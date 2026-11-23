<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUsage;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;

/**
 * Usages d'une période par Assessment : évaluations (datées par held_on), résultats calculés, conseils de classe, délibérations, demandes de bulletins.
 * Fournisseur du contrat Organisation PeriodUsage : refuse la suppression d'une période utilisée et borne le déplacement
 * de ses dates. Chaque requête porte le client (et l'établissement) et renvoie n, first_on, last_on.
 */
final class AssessmentPeriodUsage implements PeriodUsage
{
    /** Libellé de l'usage => requête de comptage (paramètres : client, établissement, période). */
    private const QUERIES = [
        'évaluation(s)' => 'SELECT COUNT(*) AS n, MIN(held_on) AS first_on, MAX(held_on) AS last_on FROM assessments WHERE tenant_id = ? AND establishment_id = ? AND period_id = ?',
        'résultat(s) calculé(s)' => 'SELECT COUNT(*) AS n, NULL AS first_on, NULL AS last_on FROM results WHERE tenant_id = ? AND establishment_id = ? AND period_id = ?',
        'conseil(s) de classe' => 'SELECT COUNT(*) AS n, NULL AS first_on, NULL AS last_on FROM class_councils WHERE tenant_id = ? AND establishment_id = ? AND period_id = ?',
        'délibération(s)' => 'SELECT COUNT(*) AS n, NULL AS first_on, NULL AS last_on FROM deliberations WHERE tenant_id = ? AND establishment_id = ? AND period_id = ?',
        'demande(s) de bulletins' => 'SELECT COUNT(*) AS n, NULL AS first_on, NULL AS last_on FROM report_card_requests WHERE tenant_id = ? AND establishment_id = ? AND period_id = ?',
    ];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function uses(string $tenantId, string $establishmentId, string $periodId): array
    {
        $uses = [];
        foreach (self::QUERIES as $label => $sql) {
            $statement = $this->connections->pdo()->prepare($sql);
            $statement->execute([$tenantId, $establishmentId, $periodId]);
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
