<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Platform\Application\KpiService;

/**
 * Indicateurs d'usage du tableau de bord (GET /platform/kpi, platform.dashboard.read). La liste nominative des personnes
 * connectées n'est rendue qu'aux rôles qui voient les utilisateurs (platform.users.read) : jamais à la finance.
 */
final class KpiAdminController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly KpiService $kpi,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.dashboard.read');

        return $this->responder->json(['data' => $this->kpi->summary($this->guard->can($principal, 'platform.users.read'))]);
    }
}
