<?php

declare(strict_types=1);

namespace Tests\Support\Staff;

use Closure;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Contrôle de payabilité d'application simulé, à la manière de l'IPES :
 * RG-ENS-1 séance contre-signée par le délégué ; RG-ENS-4 dépassement du volume de l'affectation payé seulement après accord
 * écrit ; RG-ENS-5 solde (approbation du relevé) conditionné à la saisie complète des notes de l'EC.
 */
final class ScriptedPayabilityCheck implements VacationPayabilityCheck
{
    /** @var array<string, true> heures contre-signées par le délégué */
    public array $countersigned = [];

    /** @var array<string, true> affectations dont le dépassement a un accord écrit */
    public array $overrunApproved = [];

    /** @var array<string, true> EC dont toutes les notes sont saisies */
    public array $gradesComplete = [];

    /** @var list<string> validateurs et approbateurs vus */
    public array $actors = [];

    /** @var (Closure(string): ?AssignmentInfo)|null */
    public ?Closure $assignments = null;

    public function hourRefusals(PayableHour $hour, string $validatorId): array
    {
        $this->actors[] = $validatorId;
        $refusals = [];
        if (!isset($this->countersigned[$hour->id])) {
            $refusals[] = 'séance non contre-signée par le délégué (RG-ENS-1)';
        }
        if ($hour->assignmentId !== null && $this->assignments !== null && !isset($this->overrunApproved[$hour->assignmentId])) {
            $assignment = ($this->assignments)($hour->assignmentId);
            if ($assignment !== null && $assignment->validatedMinutes + $hour->minutes > $assignment->plannedMinutes) {
                $refusals[] = 'volume de la maquette dépassé sans accord écrit (RG-ENS-4)';
            }
        }

        return $refusals;
    }

    public function statementRefusals(PayableStatement $statement, string $approverId): array
    {
        $this->actors[] = $approverId;
        $missing = [];
        foreach ($statement->lines as $line) {
            if ($line->elementRef !== null && !isset($this->gradesComplete[$line->elementRef])) {
                $missing[$line->elementRef] = 'notes de ' . $line->elementRef . ' incomplètes (RG-ENS-5)';
            }
        }

        return array_values($missing);
    }
}
