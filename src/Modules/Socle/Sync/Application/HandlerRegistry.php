<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Application;

use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;

/** Gestionnaire d'un type d'élément (`kind`), ou null s'il n'en existe pas. */
interface HandlerRegistry
{
    public function for(string $kind): ?BatchItemHandler;
}
