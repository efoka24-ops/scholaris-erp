<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsClock;
use Scholaris\Modules\Apps\Higher\Admissions\Console\ReserveExpiryCommand;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\AdmissionDirectory;
use Scholaris\Modules\Apps\Higher\Admissions\Contracts\PublicAdmissions;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\PdoAdmissionDirectory;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\PdoPublicAdmissions;

/**
 * Liaisons des admissions (fusionnées par Higher/definitions.php) : contrat offert à Enrolment, horloge, commande planifiée
 * d'expiration des « admis sous réserve » (RG-ADM-2). Les modèles DOC-01 à DOC-03 sont déclarés par le catalogue Higher\Documents.
 */
return [
    AdmissionDirectory::class => get(PdoAdmissionDirectory::class),
    PublicAdmissions::class => autowire(PdoPublicAdmissions::class),
    AdmissionsClock::class => autowire(),
    'console.command.' . ReserveExpiryCommand::NAME => autowire(ReserveExpiryCommand::class),
];
