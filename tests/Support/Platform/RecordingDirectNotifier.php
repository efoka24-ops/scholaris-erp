<?php

declare(strict_types=1);

namespace Tests\Support\Platform;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;

/** Enregistre les notifications directes de la plateforme au lieu de les envoyer. */
final class RecordingDirectNotifier implements DirectNotifier
{
    /** @var list<array{template: string, locale: string, email: ?string, phone: ?string, variables: array<string, scalar>}> */
    public array $sent = [];

    public function notify(string $templateKey, string $locale, ?string $email, ?string $phone, array $variables = []): DeliveryResult
    {
        $this->sent[] = ['template' => $templateKey, 'locale' => $locale, 'email' => $email, 'phone' => $phone, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'rec-' . count($this->sent));
    }

    /** @return list<array{template: string, locale: string, email: ?string, phone: ?string, variables: array<string, scalar>}> */
    public function of(string $template): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $m['template'] === $template));
    }
}
