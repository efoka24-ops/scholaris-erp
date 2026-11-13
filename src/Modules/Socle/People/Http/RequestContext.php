<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Ce que les contrôleurs savent de la requête : qui, pour quel client, quel établissement actif, d'où. */
final class RequestContext
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLog $audit)
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

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(ServerRequestInterface $request, string $action, string $objectType, string $objectId, ?array $before, ?array $after): void
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $device = $request->getHeaderLine('User-Agent');
        $this->audit->append(
            $this->context->tenantId(),
            $this->principal($request)->userId,
            $action,
            $objectType,
            $objectId,
            $before,
            $after,
            is_string($remote) ? $remote : null,
            $device === '' ? null : mb_substr($device, 0, 255),
        );
    }
}
