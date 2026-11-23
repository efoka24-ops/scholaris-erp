<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Tenancy\MissingTenantContext;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Organisation\Application\ModuleGuard;

/**
 * Middleware PSR-15 : la requête n'atteint le module que s'il est activé pour l'établissement actif
 * (modules_enabled). À placer après ResolveEstablishment ; sans contexte établissement, la réponse est 403.
 */
final class EnsureModuleEnabled implements MiddlewareInterface
{
    public function __construct(
        private readonly ModuleGuard $guard,
        private readonly TenantContext $context,
        private readonly string $module,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->guard->ensure($this->context->tenantId(), $this->context->establishmentId(), $this->module);
        } catch (MissingTenantContext) {
            throw HttpException::forbidden('Aucun établissement actif : accès au module refusé.');
        }

        return $handler->handle($request);
    }
}
