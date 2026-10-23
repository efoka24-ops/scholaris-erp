<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de la messagerie ordinaire (soumise au consentement) : garde chaque envoi. */
final class RecordingMessenger implements Messenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, urgent: bool}> */
    public array $sent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'urgent' => $urgent];

        return new DeliveryResult(DeliveryResult::SENT, 'spy');
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_column($this->sent, 'person');
    }
}
