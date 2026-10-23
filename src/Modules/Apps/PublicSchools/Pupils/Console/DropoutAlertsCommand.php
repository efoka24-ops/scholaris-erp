<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Console;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DropoutService;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;

/**
 * Détection quotidienne des abandons présumés (EX-ELV-06) : `public:dropout-alerts [AAAA-MM-JJ]`. Parcourt les écoles ouvertes de
 * chaque client dont le droit d'usage permet l'écriture (un client suspendu est sauté sans erreur). Peut aussi être appelée par la
 * commande quotidienne `public:absence-alerts` du module Attendance via DropoutService::detectPresumed.
 */
final class DropoutAlertsCommand
{
    public const NAME = 'public:dropout-alerts';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly DropoutService $dropouts,
        private readonly WriteGate $gate,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $today = null;
        foreach ($args as $arg) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) !== 1) {
                fwrite(STDERR, "Usage : public:dropout-alerts [AAAA-MM-JJ]\n");

                return 2;
            }
            $today = new DateTimeImmutable($arg);
        }
        $schools = $this->connections->pdo()->query("SELECT tenant_id, establishment_id FROM pub_schools WHERE status = 'open' ORDER BY tenant_id, establishment_id");
        $opened = 0;
        $skipped = 0;
        foreach ($schools !== false ? $schools->fetchAll(PDO::FETCH_ASSOC) : [] as $school) {
            if (!$this->gate->allowsWrites((string) $school['tenant_id'])) {
                ++$skipped;

                continue;
            }
            $opened += count($this->dropouts->detectPresumed((string) $school['tenant_id'], (string) $school['establishment_id'], $today));
        }
        echo sprintf("Abandons présumés ouverts : %d ; écoles sautées (application non active) : %d.\n", $opened, $skipped);

        return 0;
    }
}
