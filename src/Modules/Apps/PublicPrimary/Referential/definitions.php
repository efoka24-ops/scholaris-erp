<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Application\ReferentialService;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Http\ReferentialController;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Infrastructure\PdoReferential;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;

/** Référentiel et unités de la Primaire publique ; les mécanismes communs (Support) sont déclarés ici une seule fois. */
return [
    PrimaryClock::class => autowire(),
    PrimaryScope::class => autowire(),
    Rules::class => autowire(),
    PdoReferential::class => autowire(),
    Referential::class => get(PdoReferential::class),
    ReferentialService::class => autowire(),
    ReferentialController::class => autowire(),
];
