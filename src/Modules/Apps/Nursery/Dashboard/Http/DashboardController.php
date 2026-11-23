<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Dashboard\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Dashboard\Application\DashboardService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;

/** Tableau de bord (directrice, promoteur) : agrégats de tout l'établissement, sans donnée nominative. */
final class DashboardController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, private readonly DashboardService $dashboard, private readonly NurseryClock $clock)
    {
        parent::__construct($responder, $access);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dashboard.read']);
        $this->access->assertWhole($principal, $perimeter, 'dashboard');
        $day = $request->getQueryParams()['date'] ?? $this->clock->today();
        $parsed = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
        if (!is_string($day) || $parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $this->json($this->dashboard->summary($principal->tenantId, $establishmentId, $day));
    }
}
