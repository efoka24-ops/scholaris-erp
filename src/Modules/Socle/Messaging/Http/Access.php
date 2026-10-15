<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Ce que les contrôleurs savent de la requête (qui, quel client, quel établissement) et les permissions du module.
 * Refus par défaut : sans permission accordée par Identity, la réponse est 403.
 */
final class Access
{
    public const READ = 'messaging.read';
    public const SEND = 'messaging.send';
    public const TEMPLATES = 'messaging.templates.manage';
    public const CHAT = 'messaging.chat';
    public const MODERATE = 'messaging.moderate';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionChecker $permissions,
        private readonly AuditLog $audit,
    ) {
    }

    public function tenantId(): string
    {
        return $this->context->tenantId();
    }

    public function establishmentId(): string
    {
        return $this->context->establishmentId();
    }

    public function actorId(ServerRequestInterface $request): string
    {
        return $this->principal($request)->userId;
    }

    /** Lecture : messaging.read, ou un droit d'écriture du module. */
    public function authorizeRead(ServerRequestInterface $request): void
    {
        $this->authorize($request, [self::READ, self::SEND, self::TEMPLATES], 'consulter les messages');
    }

    public function authorizeSend(ServerRequestInterface $request): void
    {
        $this->authorize($request, [self::SEND], 'envoyer des messages');
    }

    public function authorizeChat(ServerRequestInterface $request): void
    {
        $this->authorize($request, [self::CHAT], 'utiliser la messagerie interne');
    }

    public function authorizeModerate(ServerRequestInterface $request): void
    {
        $this->authorize($request, [self::MODERATE], 'modérer la messagerie interne');
    }

    public function canModerate(ServerRequestInterface $request): bool
    {
        return $this->permissions->can($this->principal($request), self::MODERATE, $this->context->establishmentId());
    }

    public function authorizeTemplates(ServerRequestInterface $request): void
    {
        $this->authorize($request, [self::TEMPLATES], 'gérer les modèles de messages');
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

    /** @param list<string> $anyOf */
    private function authorize(ServerRequestInterface $request, array $anyOf, string $what): void
    {
        $principal = $this->principal($request);
        $establishmentId = $this->context->establishmentId();
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return;
            }
        }

        throw HttpException::forbidden('Vous n\'avez pas le droit de ' . $what . '.');
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }
}
