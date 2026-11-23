<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;

/** Refuse (403) l'accès à un module désactivé pour l'établissement. Utilisable par tout contrôleur ou middleware. */
final class ModuleGuard
{
    public function __construct(private readonly ModuleAvailability $modules)
    {
    }

    public function ensure(string $tenantId, string $establishmentId, string $module): void
    {
        if (!$this->modules->isEnabled($tenantId, $establishmentId, $module)) {
            throw HttpException::forbidden('Le module « ' . $module . ' » n\'est pas activé pour cet établissement.');
        }
    }
}
