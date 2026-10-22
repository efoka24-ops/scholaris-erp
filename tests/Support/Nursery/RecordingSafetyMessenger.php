<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;

/** Doublure du canal de sécurité (catégorie essentielle) : garde chaque envoi. */
final class RecordingSafetyMessenger implements SafetyMessenger
{
    /** @var list<array{tenant: string, person: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];

    public function send(string $tenantId, string $personId, string $templateKey, array $variables = []): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'spy');
    }

    /** @return list<string> */
    public function templates(): array
    {
        return array_column($this->sent, 'template');
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_column($this->sent, 'person');
    }
}
