<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\JsonResponder;
use Throwable;

/** GET /api/v1/health : état du service et de la base. */
final class HealthController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Config $config,
        private readonly ConnectionFactory $connections,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $database = 'non configurée';
        if ($this->connections->isConfigured()) {
            try {
                $this->connections->pdo()->query('SELECT 1');
                $database = 'ok';
            } catch (Throwable) {
                $database = 'error';
            }
        }

        $ok = $database !== 'error';
        $zone = new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'));
        $payload = [
            'status' => $ok ? 'ok' : 'degraded',
            'app' => $this->config->get('app.name'),
            'database' => $database,
            'time' => (new DateTimeImmutable('now', $zone))->format(DATE_ATOM),
        ];
        if ($this->config->get('app.debug') === true) {
            $payload['php'] = PHP_VERSION;
        }

        return $this->responder->json($payload, $ok ? 200 : 503);
    }
}
