<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Gate\Application\ClosureService;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;

/** Fermeture exceptionnelle de l'école (NOT-14) : déclaration par la direction, liste des fermetures à venir. */
final class ClosureController
{
    public const WRITE = ['public_schools.school.write', 'settings.manage'];
    public const READ = ['public_nursery.register.read', 'public_nursery.pickup.handover', 'public_schools.school.write', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly ClosureService $closures,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::WRITE);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['date', 'reason']);
        $day = RequestContext::date($body['date'] ?? null) ?? throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        $reason = $body['reason'] ?? '';
        if (!is_string($reason)) {
            throw HttpException::validation(['reason' => ['Texte attendu.']]);
        }
        $result = $this->closures->declare($principal->tenantId, $establishmentId, $day, $reason, $principal->userId);

        return $this->responder->json(['data' => $result], $result['already_declared'] === true ? 200 : 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::READ);

        return $this->responder->json(['data' => $this->closures->upcoming($principal->tenantId, $establishmentId, $this->clock->today())]);
    }
}
