<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Http\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\HigherApplication;
use Scholaris\Modules\Apps\Higher\Policies\ScopeResolver;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;

/**
 * GET /higher/me : rôles de l'utilisateur dans l'application, écoles où il exerce et état de l'abonnement ; alimente le
 * sélecteur « École » (RG-ORG-3 : affiché seulement avec plusieurs écoles ou tout l'établissement).
 */
final class MeController
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly ApplicationAccess $access,
        private readonly JsonResponder $responder,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute('principal');
        $establishmentId = $request->getAttribute('establishment_id');
        if (!$principal instanceof Principal || !is_string($establishmentId)) {
            throw HttpException::unauthorized();
        }
        $perimeter = $this->resolver->resolve($principal, 'higher.access', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::forbidden('Vous n\'avez aucun rôle dans l\'application Supérieur pour cet établissement.');
        }
        $roles = $perimeter->roles;
        sort($roles);

        return $this->responder->json(['data' => [
            'application' => [
                'key' => HigherApplication::KEY,
                'state' => $this->access->state($principal->tenantId, HigherApplication::KEY),
                'writable' => $this->access->allowsWrites($principal->tenantId, HigherApplication::KEY),
            ],
            'establishment_id' => $establishmentId,
            'roles' => $roles,
            'all_schools' => $perimeter->wholeEstablishment,
            'school_ids' => $perimeter->schoolIds,
            'school_selector' => $perimeter->showsSchoolSelector(),
        ]]);
    }
}
