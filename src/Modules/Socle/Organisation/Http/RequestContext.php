<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Ce que les contrôleurs savent de la requête : qui, pour quel client, quel établissement actif, d'où. */
final class RequestContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Repositories $repositories,
        private readonly AuditLog $audit,
    ) {
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
     * Établissement du client courant, jamais un autre : un identifiant d'un autre client donne 404.
     *
     * @return array<string, mixed>
     */
    public function establishment(string $id): array
    {
        $row = $this->repositories->request('establishments')->find($id);
        if ($row === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }

        return $row;
    }

    /** @return array<string, mixed> établissement actif (en-tête X-Establishment-Id), vérifié contre le client */
    public function activeEstablishment(): array
    {
        return $this->establishment($this->context->establishmentId());
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(ServerRequestInterface $request, string $action, string $objectType, string $objectId, ?array $before, ?array $after): void
    {
        $server = $request->getServerParams();
        $ip = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : null;
        $device = $request->getHeaderLine('User-Agent');

        $this->audit->append(
            $this->context->tenantId(),
            $this->principal($request)->userId,
            $action,
            $objectType,
            $objectId,
            $before,
            $after,
            $ip,
            $device === '' ? null : mb_substr($device, 0, 255),
        );
    }

    public function ip(ServerRequestInterface $request): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : null;
    }

    public function device(ServerRequestInterface $request): ?string
    {
        $device = $request->getHeaderLine('User-Agent');

        return $device === '' ? null : mb_substr($device, 0, 255);
    }
}
