<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;

/** Ce que les contrôleurs savent de la requête : qui, pour quel client, quel établissement actif. */
final class RequestContext
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    public function tenantId(): string
    {
        return $this->context->tenantId();
    }

    public function establishmentId(): string
    {
        return $this->context->establishmentId();
    }

    public function idempotencyKey(ServerRequestInterface $request): string
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            throw HttpException::badRequest('L\'en-tête Idempotency-Key est obligatoire.');
        }

        return $key;
    }

    public function routeParam(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : throw HttpException::notFound();
    }
}
