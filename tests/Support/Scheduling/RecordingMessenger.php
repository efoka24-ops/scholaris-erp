<?php

declare(strict_types=1);

namespace Tests\Support\Scheduling;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de Messenger : enregistre les messages demandés. */
final class RecordingMessenger implements Messenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        if ($this->fail) {
            throw new \RuntimeException('Passerelle SMS indisponible.');
        }
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'm' . count($this->sent));
    }

    /** @return list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>}> */
    public function of(string $template): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $m['template'] === $template));
    }
}
