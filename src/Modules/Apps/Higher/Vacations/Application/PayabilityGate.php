<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Application;

use DI\Container;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Conditions de payabilité déclarées au point d'extension du socle (D16, `staff.vacation.payability_checks.*`) : l'état IPES les applique
 * à chaque séance (RG-ENS-1, RG-ENS-4) et à l'état entier (RG-ENS-5) sans connaître leur implémentation.
 */
final class PayabilityGate
{
    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<string> */
    public function hourRefusals(PayableHour $hour, string $validatorId): array
    {
        $refusals = [];
        foreach ($this->checks() as $check) {
            array_push($refusals, ...$check->hourRefusals($hour, $validatorId));
        }

        return $refusals;
    }

    /** @return list<string> */
    public function statementRefusals(PayableStatement $statement, string $approverId): array
    {
        $refusals = [];
        foreach ($this->checks() as $check) {
            array_push($refusals, ...$check->statementRefusals($statement, $approverId));
        }

        return array_values(array_unique($refusals));
    }

    /** @return list<VacationPayabilityCheck> */
    private function checks(): array
    {
        $checks = [];
        foreach ($this->container->getKnownEntryNames() as $name) {
            if (!str_starts_with($name, VacationPayabilityCheck::REGISTRY_PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $check = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($check instanceof VacationPayabilityCheck) {
                    $checks[] = $check;
                }
            }
        }

        return $checks;
    }
}
