<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de Messenger : retient les messages demandés. */
final class RecordingMessenger implements Messenger
{
    /** @var list<array{person_id: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        $this->sent[] = ['person_id' => $personId, 'template' => $templateKey, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'msg-' . count($this->sent));
    }
}
