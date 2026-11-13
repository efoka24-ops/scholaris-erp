<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\OpenedEstablishments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoOpenedEstablishments;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoSetupDirectory;

/** Liaisons du paramétrage : contrats offerts aux autres sous-modules Secondary (profil, classes, coefficients, règlement intérieur). */
return [
    EstablishmentProfiles::class => autowire(ProfileResolver::class),
    ClassProfiles::class => get(PdoSetupDirectory::class),
    CoefficientBook::class => get(PdoSetupDirectory::class),
    SchoolRules::class => get(PdoSetupDirectory::class),
    OpenedEstablishments::class => get(PdoOpenedEstablishments::class),
];
