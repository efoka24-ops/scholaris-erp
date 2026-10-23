<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\ModuleAvailability;

final class PdoModuleAvailability implements ModuleAvailability
{
    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function isEnabled(string $tenantId, string $establishmentId, string $module): bool
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->find($establishmentId);
        if ($row === null) {
            return false;
        }

        return in_array($module, EstablishmentMapper::decode((string) $row['modules_enabled']), true);
    }
}
