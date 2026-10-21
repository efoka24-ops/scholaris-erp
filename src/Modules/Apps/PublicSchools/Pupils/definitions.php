<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilTransfer;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassHeadcountService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\ClassProfileService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DistributionService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DropoutService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\DuplicateScope;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\GuardianNotifier;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PublicLearnerNumbers;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilClock;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilEnrolmentService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\PupilProfileService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\TerritorialDuplicateCriteria;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Application\TransferService;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Console\DropoutAlertsCommand;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\ClassController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\DropoutController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\PupilAccess;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\PupilController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\TransferController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Infrastructure\PdoClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Infrastructure\PdoPupilRegistry;

/**
 * Élèves, classes, transferts et abandons des écoles publiques. Fournit au socle l'identifiant national (PP8,
 * `people.learner_number.providers.public_schools`) et les critères de doublon territoriaux (`people.duplicate_criteria.public_schools`).
 */
return [
    ClassRoster::class => autowire(PdoClassRoster::class),
    PupilRegistry::class => autowire(PdoPupilRegistry::class),
    PupilTransfer::class => get(TransferService::class),
    PupilClock::class => autowire(),
    DuplicateScope::class => autowire(),
    PublicLearnerNumbers::class => autowire(),
    TerritorialDuplicateCriteria::class => autowire(),
    'people.learner_number.providers.public_schools' => [PublicLearnerNumbers::class],
    'people.duplicate_criteria.public_schools' => [TerritorialDuplicateCriteria::class],
    GuardianNotifier::class => autowire(),
    PupilEnrolmentService::class => autowire(),
    PupilProfileService::class => autowire(),
    TransferService::class => autowire(),
    DropoutService::class => autowire(),
    ClassProfileService::class => autowire(),
    ClassHeadcountService::class => autowire(),
    DistributionService::class => autowire(),
    PdoClassRoster::class => autowire(),
    PupilAccess::class => autowire(),
    ClassController::class => autowire(),
    PupilController::class => autowire(),
    TransferController::class => autowire(),
    DropoutController::class => autowire(),
    'console.command.' . DropoutAlertsCommand::NAME => autowire(DropoutAlertsCommand::class),
];
