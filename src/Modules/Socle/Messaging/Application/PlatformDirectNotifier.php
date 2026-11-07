<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;
use Throwable;

/** Envoi direct d'un modèle de plateforme (transactionnel : pas de plage horaire, pas de consentement marketing). */
final class PlatformDirectNotifier implements DirectNotifier
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly MailGateway $mail,
        private readonly SmsGateway $sms,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(string $templateKey, string $locale, ?string $email, ?string $phone, array $variables = []): DeliveryResult
    {
        $locale = $locale === 'en' ? 'en' : 'fr';
        try {
            TemplateRenderer::assertVariablesAllowed($variables);
        } catch (TemplateException $e) {
            return new DeliveryResult(DeliveryResult::BLOCKED, null, $e->reason);
        }

        $sent = [];
        $reason = 'no_contact';
        foreach (['email' => $email, 'sms' => $phone] as $channel => $address) {
            if ($address === null || $address === '') {
                continue;
            }
            $template = $this->templates->resolve(TemplateRepository::PLATFORM, TemplateRepository::PLATFORM, $templateKey, $locale, $channel);
            if ($template === null) {
                $reason = 'template_missing';
                continue;
            }
            try {
                $text = TemplateRenderer::render((string) $template['body'], $variables);
                $subject = TemplateRenderer::render((string) ($template['subject'] ?? 'SCHOLARIS'), $variables);
                $sent[] = $channel === 'email' ? $this->mail->send($address, $subject, $text) : $this->sms->send($address, $text);
            } catch (TemplateException $e) {
                $reason = $e->reason;
            } catch (Throwable $e) {
                $this->logger->warning('Échec du fournisseur', ['template' => $templateKey, 'channel' => $channel, 'error' => $e::class]);
                $reason = 'gateway_error';
            }
        }

        return $sent === [] ? new DeliveryResult(DeliveryResult::BLOCKED, null, $reason) : new DeliveryResult(DeliveryResult::SENT, $sent[0]);
    }
}
