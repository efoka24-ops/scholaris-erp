<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de Messenger : enregistre les envois ; une personne « bloquee » est refusée, une personne « panne » lève une exception. */
final class RecordingMessenger implements Messenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, channels: list<string>}> */
    public array $sent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        if ($personId === 'panne') {
            throw new \RuntimeException('Passerelle e-mail indisponible.');
        }
        if ($personId === 'bloque') {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, 'no_consent');
        }
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'channels' => $channels];

        return new DeliveryResult(DeliveryResult::SENT, 'm' . count($this->sent));
    }
}
