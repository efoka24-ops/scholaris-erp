<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\SubscriptionPayments;

/** POST /platform/payments/webhook : route publique, protégée par la signature vérifiée par la passerelle (et la liste d'IP si configurée). */
final class PaymentWebhookController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly SubscriptionPayments $payments,
        private readonly Config $config,
    ) {
    }

    public function receive(ServerRequestInterface $request): ResponseInterface
    {
        $allowed = (array) $this->config->get('api.payment_webhook_ips', []);
        if ($allowed !== [] && !in_array(PlatformGuard::ip($request), $allowed, true)) {
            throw HttpException::forbidden('Adresse IP non autorisée pour ce webhook.');
        }
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
        }

        return $this->responder->json(['data' => $this->payments->handleWebhook((string) $request->getBody(), $headers)]);
    }
}
