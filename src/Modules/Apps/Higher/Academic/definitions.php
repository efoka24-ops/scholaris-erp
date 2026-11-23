<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Academic\Application\HigherEnrolmentGuard;
use Scholaris\Modules\Apps\Higher\Academic\Application\RegulationService;
use Scholaris\Modules\Apps\Higher\Academic\Console\ExpiryAlertsCommand;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\PdoAcademicDirectory;

/**
 * Liaisons d'Academic (fusionnées par Higher/definitions.php) : contrats offerts aux autres sous-modules Higher, garde
 * d'inscription enregistrée auprès du socle (D3, RG-ACA-1/2) et commande planifiée des alertes d'expiration (EX-ACA-07).
 */
return [
    AcademicDirectory::class => autowire(PdoAcademicDirectory::class),
    EnrolmentEligibility::class => get(HigherEnrolmentGuard::class),
    RegulationBook::class => get(RegulationService::class),
    HigherEnrolmentGuard::CONTAINER_KEY => [HigherEnrolmentGuard::class],
    'console.command.' . ExpiryAlertsCommand::NAME => autowire(ExpiryAlertsCommand::class),
];
