<?php

declare(strict_types=1);

namespace Scholaris\Core\Tenancy;

use LogicException;

/** Levée quand un accès aux données est tenté sans client dans le contexte (défaut = refus). */
final class MissingTenantContext extends LogicException
{
}
