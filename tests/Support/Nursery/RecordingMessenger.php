<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/** Doublure de la messagerie ordinaire (soumise au consentement) et des messages de service essentiels (D-BUL) : garde chaque envoi. */
final class RecordingMessenger implements Messenger, EssentialMessenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, urgent: bool, essential: bool}> */
    public array $sent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'urgent' => $urgent, 'essential' => false];

        return new DeliveryResult(DeliveryResult::SENT, 'spy');
    }

    public function sendEssential(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = []): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'urgent' => false, 'essential' => true];

        return new DeliveryResult(DeliveryResult::SENT, 'spy');
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_column($this->sent, 'person');
    }

    /** @return list<bool> caractère essentiel de chaque envoi de ce modèle */
    public function essentialFlags(string $template): array
    {
        return array_values(array_map(static fn (array $m): bool => $m['essential'], array_filter($this->sent, static fn (array $m): bool => $m['template'] === $template)));
    }
}
