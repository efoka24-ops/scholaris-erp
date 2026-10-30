<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsAccess;
use Scholaris\Modules\Apps\Higher\Admissions\Application\PublicLinkService;

/** Lien public « Candidater » de l'établissement : lecture, activation, désactivation et renouvellement (scolarité). */
final class PublicLinkController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PublicLinkService $links,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->links->show(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request))]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $body = Body::of($request);
        $enabled = $body->bool('enabled', true);
        $rotate = $body->bool('rotate', false);
        $body->done();

        return $this->responder->json(['data' => $this->links->update(AdmissionsAccess::principal($request), AdmissionsAccess::establishmentId($request), $enabled, $rotate)]);
    }
}
