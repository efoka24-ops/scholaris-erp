<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Attendance\Application\SetupService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Démarrage de l'application : préréglages (appel par demi-journée) et compteurs du jour (EX-PRI-29). */
final class SetupController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly SetupService $setup)
    {
        parent::__construct($responder, $access, $store);
    }

    public function status(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->setup->status($principal, $establishmentId));
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->setup->apply($principal, $establishmentId));
    }
}
