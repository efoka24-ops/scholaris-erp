<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Application;

/**
 * Permissions `primary.*` du référentiel. Lecture : tout rôle qui travaille dans l'école ; écriture : adjoint (primary.referential.manage)
 * ou direction (primary.settings.manage), sur tout l'établissement.
 */
final class Permissions
{
    public const READ = ['primary.referential.read', 'primary.referential.manage', 'primary.settings.manage'];
    public const MANAGE = ['primary.referential.manage', 'primary.settings.manage'];

    private function __construct()
    {
    }
}
