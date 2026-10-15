<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;

/**
 * Séparation plateforme / client :
 *  - routes /platform/** : compte du client technique `platform` ET permission `platform.access` ET permission de l'action ;
 *    actions sensibles : double authentification validée depuis moins de 15 minutes (obligatoire pour ce rôle, quel que
 *    soit AUTH_2FA_REQUIRED) ;
 *  - routes des clients : jamais un compte de la plateforme (l'administrateur de plateforme ne voit pas les données métier).
 */
final class PlatformGuard
{
    public const SECOND_FACTOR_MAX_AGE = 900;

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly RecentSecondFactor $secondFactor,
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

    public function platformAdmin(ServerRequestInterface $request, string $permission, bool $sensitive = false): Principal
    {
        $principal = $this->principal($request);
        if ($principal->tenantId !== PlatformAudit::PLATFORM_TENANT
            || str_starts_with($principal->userId, 'svc:')
            || !$this->permissions->can($principal, 'platform.access')
            || !$this->permissions->can($principal, $permission)) {
            throw HttpException::forbidden('Réservé à l\'administrateur de plateforme.');
        }
        if ($sensitive && !$this->secondFactor->hasRecent($principal->tenantId, $principal->userId, self::SECOND_FACTOR_MAX_AGE)) {
            throw new HttpException(
                403,
                'Double authentification récente requise',
                'Validez à nouveau votre double authentification pour cette action.',
                'https://api.scholaris.cm/errors/two-factor-recent-required',
            );
        }

        return $principal;
    }

    /** Utilisateur d'un client autorisé à ouvrir et payer des applications pour l'établissement actif. */
    public function clientManager(ServerRequestInterface $request, string $establishmentId): Principal
    {
        $principal = $this->clientUser($request);
        if (!$this->permissions->can($principal, 'applications.manage', $establishmentId)) {
            throw HttpException::forbidden();
        }

        return $principal;
    }

    public function clientUser(ServerRequestInterface $request): Principal
    {
        $principal = $this->principal($request);
        if ($principal->tenantId === PlatformAudit::PLATFORM_TENANT) {
            throw HttpException::forbidden('Un compte de la plateforme n\'accède pas aux espaces des clients.');
        }

        return $principal;
    }

    public static function ip(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : 'inconnue';
    }

    public static function param(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && $value !== '' && strlen($value) <= 80 ? $value : throw HttpException::notFound();
    }
}
