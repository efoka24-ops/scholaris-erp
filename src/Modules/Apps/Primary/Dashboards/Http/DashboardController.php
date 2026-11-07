<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\ClosureReadiness;
use Scholaris\Modules\Apps\Primary\Dashboards\Application\DirectorDashboard;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/** Tableau de bord du directeur et contrôles avant clôture de l'année (EX-PRI-43, 44, 46) : lectures seules. */
final class DashboardController extends Controller
{
    public const CLOSURE_READ = ['primary.settings.manage', 'primary.promotion.decide', 'primary.dashboard.read'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly DirectorDashboard $dashboard,
        private readonly ClosureReadiness $readiness,
        private readonly CurrentPeriod $periods,
        private readonly PrimaryClock $clock,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->dashboard->build($principal, $establishmentId));
    }

    public function closureCheck(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::CLOSURE_READ);
        $this->access->assertWhole($principal, $perimeter, 'pri_year_closure');
        $year = $request->getQueryParams()['academic_year_id'] ?? null;
        $yearId = is_string($year) && $year !== '' ? $year : $this->periods->at($principal->tenantId, $establishmentId, $this->clock->today())?->academicYearId;
        if ($yearId === null) {
            throw HttpException::validation(['academic_year_id' => ['Année scolaire obligatoire (aucune période en cours pour la déduire).']]);
        }

        return $this->json($this->readiness->report($principal->tenantId, $establishmentId, $yearId));
    }
}
