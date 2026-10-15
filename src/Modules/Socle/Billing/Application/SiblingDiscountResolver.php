<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Remise de fratrie, y compris entre établissements d'un même client : les frères et soeurs sont les apprenants
 * des mêmes garants financiers qui ont une inscription active où que ce soit dans le client. Ils sont classés par
 * identifiant (ordre d'enregistrement) ; le premier ne reçoit rien, chacun des suivants reçoit la remise.
 */
final class SiblingDiscountResolver
{
    public function __construct(
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
    ) {
    }

    public function isEligible(string $tenantId, string $learnerId): bool
    {
        $siblings = [$learnerId => true];
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guarantor) {
            foreach ($this->people->learnersOf($tenantId, $guarantor->id) as $learner) {
                if ($this->hasActiveEnrollment($tenantId, $learner->id)) {
                    $siblings[$learner->id] = true;
                }
            }
        }

        $ids = array_map('strval', array_keys($siblings));
        sort($ids);

        return count($ids) > 1 && $ids[0] !== $learnerId;
    }

    private function hasActiveEnrollment(string $tenantId, string $learnerId): bool
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->status === EnrollmentInfo::ACTIVE) {
                return true;
            }
        }

        return false;
    }
}
