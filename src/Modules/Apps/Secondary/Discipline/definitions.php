<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Discipline\Application\StoredConductRecord;
use Scholaris\Modules\Apps\Secondary\Discipline\Contracts\ConductRecord;

/** Contrat de la discipline offert aux autres sous-modules Secondary (Councils, T902 : conduite au conseil de classe). */
return [
    ConductRecord::class => get(StoredConductRecord::class),
];
