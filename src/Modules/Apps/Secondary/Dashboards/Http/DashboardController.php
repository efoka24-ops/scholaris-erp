<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\ClaimRegister;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\DashboardService;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\NetworkDashboard;
use Scholaris\Modules\Apps\Secondary\Dashboards\Application\ReportBuilder;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Tableaux de bord (chef, censeur, intendant, réseau), registre des réclamations (O1) et rapports exportables. */
final class DashboardController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly DashboardService $dashboards,
        private readonly NetworkDashboard $network,
        private readonly ClaimRegister $claims,
        private readonly ReportBuilder $reports,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function head(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->dashboards->head($principal, $establishmentId, $request->getQueryParams()));
    }

    public function pedagogy(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->dashboards->pedagogy($principal, $establishmentId, $request->getQueryParams()));
    }

    public function finance(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->dashboards->finance($principal, $establishmentId, $request->getQueryParams()));
    }

    public function network(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->network->build($principal, $establishmentId, $request->getQueryParams()));
    }

    public function claims(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page($this->claims->list($principal, $establishmentId, $request));
    }

    public function recordClaim(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->claims->record($principal, $establishmentId, Input::body($request)), 201);
    }

    public function closeClaim(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->claims->close($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function report(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $file = $this->reports->export($principal, $establishmentId, self::id($request, 'report'), $request->getQueryParams());

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $file->contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->filename) . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStream($file->content));
    }
}
