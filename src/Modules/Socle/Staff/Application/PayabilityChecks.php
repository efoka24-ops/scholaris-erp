<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Application;

use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Découvre les contrôles de payabilité déclarés par les applications (entrées de liste `staff.vacation.payability_checks.*`,
 * triées par nom) et refuse (409) l'heure ou le relevé si l'un d'eux donne un motif.
 */
final class PayabilityChecks
{
    /** @var list<VacationPayabilityCheck>|null */
    private ?array $checks = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<VacationPayabilityCheck> */
    public function all(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, VacationPayabilityCheck::REGISTRY_PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $check = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($check instanceof VacationPayabilityCheck) {
                    $found[spl_object_id($check)] = $check;
                }
            }
        }

        return $this->checks = array_values($found);
    }

    public function assertHourPayable(PayableHour $hour, string $validatorId): void
    {
        $refusals = [];
        foreach ($this->all() as $check) {
            array_push($refusals, ...$check->hourRefusals($hour, $validatorId));
        }
        self::refuse($refusals, 'Heure non payable : ');
    }

    public function assertStatementPayable(PayableStatement $statement, string $approverId): void
    {
        $refusals = [];
        foreach ($this->all() as $check) {
            array_push($refusals, ...$check->statementRefusals($statement, $approverId));
        }
        self::refuse($refusals, 'Relevé non payable : ');
    }

    /** @param list<string> $refusals */
    private static function refuse(array $refusals, string $prefix): void
    {
        if ($refusals !== []) {
            throw HttpException::conflict($prefix . implode(' ; ', array_values(array_unique($refusals))));
        }
    }
}
