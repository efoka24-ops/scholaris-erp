<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Attendance\Application\AssiduityService;
use Scholaris\Modules\Socle\Attendance\Application\AttendanceAccess;

/** Assiduité d'un apprenant (lecture par la vie scolaire, l'apprenant ou son parent) et liste des non-autorisés à composer. */
final class AssiduityController extends AttendanceController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AttendanceAccess $access,
        private readonly AssiduityService $service,
    ) {
        parent::__construct($responder, $context, $access);
    }

    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $learnerId = $this->param($request, 'id');
        if (!$this->access->canViewLearner($principal, $this->context->establishmentId(), $learnerId)) {
            throw HttpException::notFound('Apprenant introuvable.');
        }
        $from = $this->requireDate($this->queryString($request, 'from'), 'from');
        $to = $this->requireDate($this->queryString($request, 'to'), 'to');

        return $this->responder->json(['data' => $this->service->summary($this->context->tenantId(), $this->context->establishmentId(), $learnerId, $from, $to)]);
    }

    public function notAllowedToSit(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, AttendanceAccess::READ);
        $query = ListQuery::fromRequest($request);
        $from = $this->requireDate($this->queryString($request, 'from'), 'from');
        $to = $this->requireDate($this->queryString($request, 'to'), 'to');
        $result = $this->service->notAllowedToSit($this->context->tenantId(), $this->context->establishmentId(), $from, $to, $this->queryString($request, 'structure_id'));

        // A3 : pagination par curseur. La liste est triée (taux décroissant puis personne) : le curseur porte le rang du
        // dernier élément servi, opaque pour le client.
        $offset = 0;
        $decoded = ListQuery::decodeCursor($query->cursor);
        if ($query->cursor !== null) {
            if ($decoded === null || preg_match('/^o:(\d{1,9})$/', $decoded, $match) !== 1) {
                throw HttpException::validation(['cursor' => ['Curseur invalide.']], 'Paramètres de liste invalides.');
            }
            $offset = (int) $match[1];
        }
        $page = array_slice($result['data'], $offset, $query->perPage);
        $next = $offset + $query->perPage < count($result['data']) ? ListQuery::encodeCursor('o:' . ($offset + $query->perPage)) : null;

        return $this->responder->json(['data' => $page, 'meta' => ['thresholds' => $result['thresholds'], 'next_cursor' => $next]]);
    }
}
