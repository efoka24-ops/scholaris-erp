<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Billing\Application\WebhookService;

/** POST /webhooks/payments/{provider} : route publique, protégée par la signature vérifiée par la passerelle de paiement (et une liste d'IP si configurée). */
final class WebhookController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly WebhookService $service,
        private readonly Config $config,
    ) {
    }

    public function receive(ServerRequestInterface $request): ResponseInterface
    {
        $provider = (string) $request->getAttribute('provider');
        if (preg_match('/^[a-z0-9_]{2,40}$/', $provider) !== 1) {
            throw HttpException::notFound();
        }

        $allowed = (array) $this->config->get('api.payment_webhook_ips', []);
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if ($allowed !== [] && (!is_string($ip) || !in_array($ip, $allowed, true))) {
            throw HttpException::forbidden('Adresse IP non autorisée pour ce webhook.');
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }

        $outcome = $this->service->handle($provider, (string) $request->getBody(), $headers);

        return $this->responder->json(['data' => $outcome]);
    }
}
