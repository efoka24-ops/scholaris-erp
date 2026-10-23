<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Messaging\Http\Access;
use Scholaris\Modules\Socle\Messaging\Infrastructure\SmsUsageRepository;

/** Consommation de SMS de l'établissement actif, par mois (base de la facturation). */
final class SmsUsageController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly Access $access,
        private readonly SmsUsageRepository $usage,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->authorizeRead($request);
        $month = ListQuery::fromRequest($request, ['month'])->filters['month'] ?? null;
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::validation(['filter[month]' => ['Le mois doit être au format AAAA-MM.']]);
        }

        return $this->responder->json(['data' => $this->usage->list($this->access->tenantId(), $this->access->establishmentId(), $month)]);
    }
}
