<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicNursery\Documents\Application\CardRevoker;
use Scholaris\Modules\Apps\PublicNursery\Documents\Application\PickupDocuments;
use Scholaris\Modules\Apps\PublicNursery\Documents\Http\PickupDocumentController;

/** Documents de la porte (DOC-21 carte, DOC-19 registre) émis par le module Documents du socle (C-106, T727). */
return [
    CardRevoker::class => autowire(),
    PickupDocuments::class => autowire(),
    PickupDocumentController::class => autowire(),
];
