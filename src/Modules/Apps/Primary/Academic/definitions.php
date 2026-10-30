<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Infrastructure\PdoCompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Infrastructure\PdoLevelDirectory;

/** Liaisons du référentiel : contrats offerts aux autres sous-modules Primary. */
return [
    LevelDirectory::class => autowire(PdoLevelDirectory::class),
    CompetencyDirectory::class => autowire(PdoCompetencyDirectory::class),
];
