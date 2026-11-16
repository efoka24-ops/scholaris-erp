<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\EssentialMessenger;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;

/**
 * Doublure de Messenger et d'EssentialMessenger : enregistre les envois. Une personne « bloquee » est refusée (consentement absent),
 * une personne « desinscrit » est refusée dans les deux cas (opted_out), une personne « panne » lève une exception. Un envoi
 * ordinaire exige le consentement accordé par `consent()` ; un envoi essentiel ne l'exige pas.
 */
final class RecordingMessenger implements Messenger, EssentialMessenger
{
    /** @var list<array{tenant: string, establishment: string, person: string, template: string, variables: array<string, scalar>, channels: list<string>, essential: bool}> */
    public array $sent = [];

    /** @var array<string, true> personnes sans consentement (envoi ordinaire bloqué) */
    private array $withoutConsent = [];

    public function withoutConsent(string $personId): void
    {
        $this->withoutConsent[$personId] = true;
    }

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        if ($personId === 'bloque' || isset($this->withoutConsent[$personId])) {
            return $this->guard($personId) ?? new DeliveryResult(DeliveryResult::BLOCKED, null, 'consent_missing');
        }

        return $this->guard($personId) ?? $this->record($tenantId, $establishmentId, $personId, $templateKey, $variables, $channels, false);
    }

    public function sendEssential(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = []): DeliveryResult
    {
        return $this->guard($personId) ?? $this->record($tenantId, $establishmentId, $personId, $templateKey, $variables, $channels, true);
    }

    private function guard(string $personId): ?DeliveryResult
    {
        if ($personId === 'panne') {
            throw new \RuntimeException('Passerelle e-mail indisponible.');
        }

        return $personId === 'desinscrit' ? new DeliveryResult(DeliveryResult::BLOCKED, null, 'opted_out') : null;
    }

    /**
     * @param array<string, scalar> $variables
     * @param list<string> $channels
     */
    private function record(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables, array $channels, bool $essential): DeliveryResult
    {
        $this->sent[] = ['tenant' => $tenantId, 'establishment' => $establishmentId, 'person' => $personId, 'template' => $templateKey, 'variables' => $variables, 'channels' => $channels, 'essential' => $essential];

        return new DeliveryResult(DeliveryResult::SENT, 'm' . count($this->sent));
    }
}
