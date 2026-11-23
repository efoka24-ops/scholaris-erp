<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Enrolment\Application\LearnerProfileService;
use Scholaris\Modules\Apps\Primary\Enrolment\Application\PromotionMapChecker;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Profil d'élève (redoublant calculé) et contrôle de la table de passage (EX-PRI-14, 46). */
final class LearnerController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly LearnerProfileService $profiles, private readonly PromotionMapChecker $promotion)
    {
        parent::__construct($responder, $access, $store);
    }

    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $year = $request->getQueryParams()['academic_year_id'] ?? null;

        return $this->json($this->profiles->profile($principal, $establishmentId, self::id($request), is_string($year) && $year !== '' ? $year : null));
    }

    public function promotionCheck(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->promotion->check($principal, $establishmentId));
    }
}
