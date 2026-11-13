<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Apee\Application\ApeeClock;
use Scholaris\Modules\Apps\PublicSchools\Apee\Application\ApeeService;
use Scholaris\Modules\Apps\PublicSchools\Apee\Application\SupplyService;
use Scholaris\Modules\Apps\PublicSchools\Apee\Http\ApeeController;
use Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure\PdoApee;
use Scholaris\Modules\Apps\PublicSchools\Apee\Portal\ReceiptsSection;

/** APEE, paquet minimum et dons ; section « reçus » du portail famille (`portals.sections.public_schools.apee`). */
return [
    ApeeClock::class => autowire(),
    PdoApee::class => autowire(),
    ApeeService::class => autowire(),
    SupplyService::class => autowire(),
    ApeeController::class => autowire(),
    ReceiptsSection::class => autowire(),
    'portals.sections.public_schools.apee' => [ReceiptsSection::class],
];
