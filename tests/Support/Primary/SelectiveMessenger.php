<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/**
 * Doublure de Messenger et d'EssentialMessenger, qui imite la règle du socle :
 *  - $blocked : refus explicite ou contact manquant (opted_out, consent_revoked, no_contact…), bloque tout envoi ;
 *  - $withoutConsent : personnes sans consentement recueilli, bloquées (consent_missing) sauf pour un message de service essentiel (D-BUL).
 */
final class SelectiveMessenger implements Messenger, EssentialMessenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, essential: bool}> */
    public array $sent = [];

    /** @var array<string, string> personne => motif (opted_out, no_contact…) */
    public array $blocked = [];

    /** @var list<string> personnes sans consentement aux communications */
    public array $withoutConsent = [];

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        if (in_array($personId, $this->withoutConsent, true)) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, 'consent_missing');
        }

        return $this->record($tenantId, $establishmentId, $personId, $templateKey, $variables, false);
    }

    public function sendEssential(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = []): DeliveryResult
    {
        return $this->record($tenantId, $establishmentId, $personId, $templateKey, $variables, true);
    }

    /** @return list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, essential: bool}> */
    public function of(string $template): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $m['template'] === $template));
    }

    /** @param array<string, scalar> $variables */
    private function record(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables, bool $essential): DeliveryResult
    {
        if (isset($this->blocked[$personId])) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, $this->blocked[$personId]);
        }
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'essential' => $essential];

        return new DeliveryResult(DeliveryResult::SENT, 'm' . count($this->sent));
    }
}
