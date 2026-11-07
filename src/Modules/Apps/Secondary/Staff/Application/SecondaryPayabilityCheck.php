<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Socle\Staff\Contracts\PayableHour;
use Scholaris\Modules\Socle\Staff\Contracts\PayableStatement;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Payabilité des heures du secondaire (T911, EX-RHP-02), ajoutée aux règles du socle (émargement, cahier renseigné, contre-signature
 * par un autre que le déclarant) avant la validation par le censeur :
 *  - l'heure doit venir d'un appel validé : le socle Attendance la signale par TaughtHoursRecorder, sans déclarant et sur un créneau ;
 *    une heure saisie à la main (déclarant renseigné) n'est jamais payable ;
 *  - la séance doit être signée au cahier de textes par l'enseignant sur ce créneau et à cette date.
 * Un établissement qui n'est pas un secondaire ouvert n'est pas concerné (autres applications du client inchangées).
 */
final class SecondaryPayabilityCheck implements VacationPayabilityCheck
{
    public const NO_ROLL_CALL = 'Heure saisie sans appel : seules les séances dont l\'appel a été validé sont payables.';
    public const NO_SIGNED_LESSON = 'Aucune séance signée au cahier de textes ne correspond à cet appel (créneau et date).';

    public function __construct(
        private readonly EstablishmentProfiles $profiles,
        private readonly SignedLessons $lessons,
    ) {
    }

    public function hourRefusals(PayableHour $hour, string $validatorId): array
    {
        if (!$this->concerns($hour->tenantId, $hour->establishmentId)) {
            return [];
        }
        if ($hour->declaredBy !== null || $hour->slotId === null) {
            return [self::NO_ROLL_CALL];
        }
        if (!$this->lessons->signedFor($hour->tenantId, $hour->establishmentId, $hour->personId, $hour->slotId, $hour->heldOn)) {
            return [self::NO_SIGNED_LESSON];
        }

        return [];
    }

    public function statementRefusals(PayableStatement $statement, string $approverId): array
    {
        $refusals = [];
        foreach ($statement->lines as $hour) {
            array_push($refusals, ...$this->hourRefusals($hour, $approverId));
        }

        return array_values(array_unique($refusals));
    }

    private function concerns(string $tenantId, string $establishmentId): bool
    {
        try {
            return $this->profiles->forEstablishment($tenantId, $establishmentId)->stored;
        } catch (HttpException) {
            return false;
        }
    }
}
