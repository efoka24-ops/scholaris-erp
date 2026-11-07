<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\HealthService;

/** Santé de la plateforme (GET /platform/health) : sondes, valeurs et seuils (platform.health.read). */
final class HealthAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly HealthService $health,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAdmin($request, 'platform.health.read');

        return $this->responder->json(['data' => $this->health->report()]);
    }
}
