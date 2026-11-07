<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Academic;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de Messenger : garde les envois (aucun réseau). */
final class RecordingMessenger implements Messenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'msg-' . count($this->sent));
    }
}
