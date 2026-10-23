<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;

/**
 * Pour les routes métier : exige l'en-tête X-Establishment-Id, vérifie les droits de la personne sur
 * cet établissement, puis renseigne le TenantContext. Pour les routes sans établissement
 * (auth seule), seul le client est placé dans le contexte. Toute autre situation est un refus.
 */
final class ResolveEstablishment implements MiddlewareInterface
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly TenantContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->clear();

        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            return $handler->handle($request);
        }

        $options = (array) $request->getAttribute('route_options', []);
        if (($options['tenant'] ?? true) === false) {
            $this->context->set($principal->tenantId);

            return $handler->handle($request);
        }

        $establishmentId = trim($request->getHeaderLine('X-Establishment-Id'));
        if ($establishmentId === '') {
            throw HttpException::badRequest('L\'en-tête X-Establishment-Id est obligatoire.');
        }
        if (!$this->access->canAccess($principal, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas accès à cet établissement.');
        }

        $this->context->set($principal->tenantId, $establishmentId);

        return $handler->handle($request->withAttribute('establishment_id', $establishmentId));
    }
}
