<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Messaging\Domain\Channel;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Infrastructure\DeliveryRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\SmsUsageRepository;
use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Traite UNE livraison : consentement, contact, plage horaire (hors urgence), rendu du modèle dans la langue du
 * destinataire (repli sur le français), envoi par le port du canal, comptage des SMS. Les journaux ne contiennent
 * ni numéro, ni adresse, ni texte du message.
 */
final class DeliveryProcessor
{
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const QUEUED = 'queued';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';

    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly TemplateRepository $templates,
        private readonly PersonDirectory $persons,
        private readonly ConsentChecker $consents,
        private readonly EstablishmentDirectory $establishments,
        private readonly SmsGateway $sms,
        private readonly MailGateway $mail,
        private readonly SmsUsageRepository $usage,
        private readonly MessagingSettings $settings,
        private readonly MessagingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $delivery
     * @param array<string, mixed> $message
     * @return array{status: string, reason: string|null}
     */
    public function process(array $delivery, array $message): array
    {
        $tenantId = (string) $delivery['tenant_id'];
        $establishmentId = (string) $delivery['establishment_id'];
        $channel = (string) $delivery['channel'];
        $now = $this->clock->now();

        $outcome = $this->decide($delivery, $message, $tenantId, $establishmentId, $channel, $now);

        $values = ['status' => $outcome['status'], 'reason' => $outcome['reason'], 'updated_at' => $this->clock->iso($now)];
        if ($outcome['status'] === self::QUEUED) {
            $values['not_before'] = $outcome['not_before'] ?? null;
        } else {
            $values['not_before'] = null;
        }
        if (in_array($outcome['status'], [self::SENT, self::DELIVERED], true)) {
            $values['provider_reference'] = $outcome['reference'] ?? null;
            $values['sent_at'] = $this->clock->iso($now);
            $values['attempts'] = (int) $delivery['attempts'] + 1;
        }
        if ($outcome['status'] === self::DELIVERED) {
            $values['delivered_at'] = $this->clock->iso($now);
        }
        if ($outcome['status'] === self::FAILED) {
            $values['attempts'] = (int) $delivery['attempts'] + 1;
        }
        $this->deliveries->update($tenantId, (string) $delivery['id'], $values);

        if ($channel === Channel::SMS && in_array($outcome['status'], [self::SENT, self::DELIVERED], true)) {
            $this->usage->increment($tenantId, $establishmentId, $this->clock->month(), $this->clock->iso($now));
        }
        $this->logger->info('Livraison traitée', [
            'delivery' => $delivery['id'],
            'channel' => $channel,
            'status' => $outcome['status'],
            'reason' => $outcome['reason'],
        ]);

        return ['status' => $outcome['status'], 'reason' => $outcome['reason']];
    }

    /**
     * @param array<string, mixed> $delivery
     * @param array<string, mixed> $message
     * @return array{status: string, reason: string|null, reference?: string, not_before?: string}
     */
    private function decide(array $delivery, array $message, string $tenantId, string $establishmentId, string $channel, \DateTimeImmutable $now): array
    {
        $personId = (string) $delivery['recipient_id'];
        if (!$this->consents->hasConsent($tenantId, $personId, ConsentChecker::COMMUNICATION)) {
            return ['status' => self::BLOCKED, 'reason' => 'consent_missing'];
        }
        $person = $this->persons->find($tenantId, $personId);
        if ($person === null) {
            return ['status' => self::BLOCKED, 'reason' => 'recipient_unknown'];
        }
        $address = match ($channel) {
            Channel::SMS => $person->phone,
            Channel::EMAIL => $person->email,
            default => $personId,
        };
        if ($address === null || $address === '') {
            return ['status' => self::BLOCKED, 'reason' => 'no_contact'];
        }

        $window = $this->settings->window($tenantId, $establishmentId);
        if ((int) $message['urgent'] !== 1 && !$window->isOpen($now)) {
            return ['status' => self::QUEUED, 'reason' => 'outside_window', 'not_before' => $this->clock->iso($window->nextOpening($now))];
        }

        $locale = in_array($person->locale, ['fr', 'en'], true) ? $person->locale : 'fr';
        $variables = array_replace(
            ['first_name' => $person->firstName, 'last_name' => $person->lastName, 'full_name' => $person->fullName()],
            $this->establishmentName($tenantId, $establishmentId),
            $this->decode($delivery['variables'] ?? null),
            $this->decode($message['variables'] ?? null),
        );

        try {
            [$subject, $text] = $this->content($message, $tenantId, $establishmentId, $channel, $locale, $variables);
        } catch (TemplateException $e) {
            return ['status' => self::BLOCKED, 'reason' => $e->reason];
        }

        try {
            return match ($channel) {
                Channel::SMS => ['status' => self::SENT, 'reason' => null, 'reference' => $this->sms->send($address, $text)],
                Channel::EMAIL => ['status' => self::SENT, 'reason' => null, 'reference' => $this->mail->send($address, $subject, $text)],
                default => ['status' => self::DELIVERED, 'reason' => null, 'reference' => 'inapp-' . new Ulid()],
            };
        } catch (Throwable $e) {
            $this->logger->warning('Échec du fournisseur', ['delivery' => $delivery['id'], 'channel' => $channel, 'error' => $e::class]);

            return ['status' => self::FAILED, 'reason' => 'gateway_error'];
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, scalar> $variables
     * @return array{0: string, 1: string} sujet et texte
     */
    private function content(array $message, string $tenantId, string $establishmentId, string $channel, string $locale, array $variables): array
    {
        $key = $message['template_key'] ?? null;
        if (!is_string($key) || $key === '') {
            return ['Message', TemplateRenderer::render((string) $message['body'], $variables)];
        }
        foreach (array_unique([$locale, 'fr']) as $language) {
            $template = $this->templates->resolve($tenantId, $establishmentId, $key, $language, $channel);
            if ($template !== null) {
                $subject = is_string($template['subject'] ?? null) && $template['subject'] !== '' ? $template['subject'] : $key;

                return [TemplateRenderer::render($subject, $variables), TemplateRenderer::render((string) $template['body'], $variables)];
            }
        }

        throw new TemplateException('template_missing', 'Aucun modèle pour ce canal.');
    }

    /** @return array<string, string> */
    private function establishmentName(string $tenantId, string $establishmentId): array
    {
        $info = $this->establishments->find($tenantId, $establishmentId);

        return $info === null ? [] : ['establishment_name' => $info->name];
    }

    /** @return array<string, scalar> */
    private function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? array_filter($data, 'is_scalar') : [];
    }
}
