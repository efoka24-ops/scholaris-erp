<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Attendance\Application\CallRosterService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Liste d'appel d'une classe (appel par demi-journée). */
final class CallRosterController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly CallRosterService $rosters)
    {
        parent::__construct($responder, $access, $store);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->rosters->roster($principal, $establishmentId, self::id($request)));
    }
}
