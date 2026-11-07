<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

final class SafetyMessengerImpl implements SafetyMessenger
{
    public function __construct(
        private readonly PersonDirectory $people,
        private readonly DirectNotifier $notifier,
    ) {
    }

    public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): DeliveryResult
    {
        $person = $this->people->find($tenantId, $personId);
        if ($person === null) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, 'recipient_unknown');
        }

        return $this->notifier->notify($templateKey, $person->locale, $person->email, $person->phone, $variables + [
            'first_name' => $person->firstName,
            'last_name' => $person->lastName,
            'full_name' => $person->fullName(),
        ]);
    }
}