<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Dit si un module du socle est activé pour un établissement (modules_enabled). Refus si l'établissement est inconnu du client. */
interface ModuleAvailability
{
    public function isEnabled(string $tenantId, string $establishmentId, string $module): bool;
}
