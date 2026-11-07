<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Mode des frais d'un établissement, lu sur son profil (jamais sur son type, D9) : `private` [fees_private], `public_catalog`
 * [fees_public_catalog], `none` (aucun des deux), ou null pour un établissement qui n'est pas du secondaire (les gardes du socle
 * l'ignorent alors).
 */
final class FeesMode
{
    public const PRIVATE = 'private';
    public const PUBLIC_CATALOG = 'public_catalog';
    public const NONE = 'none';

    public function __construct(private readonly EstablishmentProfiles $profiles)
    {
    }

    public function of(string $tenantId, string $establishmentId): ?string
    {
        try {
            $profile = $this->profiles->forEstablishment($tenantId, $establishmentId);
        } catch (HttpException) {
            return null;
        }

        return match (true) {
            $profile->isActive(Modules::FEES_PUBLIC_CATALOG) => self::PUBLIC_CATALOG,
            $profile->isActive(Modules::FEES_PRIVATE) => self::PRIVATE,
            default => self::NONE,
        };
    }
}
