<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application\Guards;

use Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationContext;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/**
 * Alerte à la réouverture d'une délibération close (S8, alerte du directeur général) : un message à chaque personne
 * déclarée dans assessment.deliberation.reopen_alert. Sans paramètre, rien n'est envoyé.
 */
final class ReopenAlertGuard implements DeliberationGuard
{
    public function __construct(
        private readonly AssessmentSettings $settings,
        private readonly Messenger $messenger,
    ) {
    }

    public function refusals(DeliberationContext $context): array
    {
        return [];
    }

    public function after(DeliberationContext $context): void
    {
        if ($context->stage !== self::REOPEN) {
            return;
        }
        $alert = $this->settings->reopenAlert($context->tenantId, $context->establishmentId);
        if ($alert === null) {
            return;
        }
        foreach ($alert['person_ids'] as $personId) {
            $this->messenger->send($context->tenantId, $context->establishmentId, $personId, $alert['template'], [
                'structure' => $context->structureId,
                'period' => $context->periodId,
                'session' => $context->session,
                'reason' => (string) $context->reason,
                'minutes_version' => $context->minutesVersion,
            ], [], true);
        }
    }
}
