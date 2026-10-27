<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicNursery\Development\Application\FrameService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\NeedReportService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\PublicNursery\Development\Console\ObservationRemindersCommand;
use Scholaris\Modules\Apps\PublicNursery\Development\Console\PurgeMediaCommand;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\FrameController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\LifeBookController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\NeedReportController;
use Scholaris\Modules\Apps\PublicNursery\Development\Http\ObservationController;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\CustodyRestrictionService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\HandoverService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\AuthorizedPersonsController;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\CustodyRestrictionController;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\HandoverController;
use Scholaris\Modules\Apps\PublicNursery\Portal\Http\AllergyController;
use Scholaris\Modules\Apps\PublicNursery\Portal\Http\PortalPickupController;
use Scholaris\Modules\Apps\PublicNursery\Portal\Sections\LifeBookSection;
use Scholaris\Modules\Apps\PublicNursery\Portal\Sections\ProgressSection;
use Scholaris\Modules\Apps\PublicNursery\PublicNurseryApplication;
use Scholaris\Modules\Apps\PublicNursery\Support\EstablishmentGuard;
use Scholaris\Modules\Apps\PublicNursery\Support\GatedAction;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryWriteGate;
use Scholaris\Modules\Apps\PublicNursery\Support\SectionScopeResolver;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\LiaisonSheetService;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\NurseryPromotionPlanner;
use Scholaris\Modules\Apps\PublicNursery\Transition\Application\TransitionService;
use Scholaris\Modules\Apps\PublicNursery\Transition\Http\LiaisonController;
use Scholaris\Modules\Apps\PublicNursery\Transition\Http\TransitionController;

/**
 * Definitions racine de l'application Maternelle publique : un manifeste de plateforme et le chargement futur
 * des sous-modules via leurs propres definitions.php.
 */
$definitions = [
    PublicNurseryApplication::CONTAINER_KEY => PublicNurseryApplication::manifest(),
    FrameService::class => autowire(),
    FrameController::class => autowire(),
    LifeBookService::class => autowire(),
    LifeBookController::class => autowire(),
    ObservationService::class => autowire(),
    ObservationController::class => autowire(),
    ProgressBookService::class => autowire(),
    \Scholaris\Modules\Apps\PublicNursery\Development\Http\ProgressBookController::class => autowire(),
    NeedReportService::class => autowire(),
    NeedReportController::class => autowire(),
    AuthorizedPersonsService::class => autowire(),
    HandoverService::class => autowire(),
    CustodyRestrictionService::class => autowire(),
    AuthorizedPersonsController::class => autowire(),
    HandoverController::class => autowire(),
    CustodyRestrictionController::class => autowire(),
    PortalPickupController::class => autowire(),
    AllergyController::class => autowire(),
    TransitionService::class => autowire(),
    LiaisonSheetService::class => autowire(),
    TransitionController::class => autowire(),
    LiaisonController::class => autowire(),
    NurseryPromotionPlanner::class => autowire(),
    NurseryPromotionPlanner::CONTAINER_KEY => [NurseryPromotionPlanner::class],
    ProgressSection::class => autowire(),
    LifeBookSection::class => autowire(),
    'portals.sections.public_nursery' => [ProgressSection::class, LifeBookSection::class],
    'console.command.' . ObservationRemindersCommand::NAME => autowire(ObservationRemindersCommand::class),
    'console.command.' . PurgeMediaCommand::NAME => autowire(PurgeMediaCommand::class),
    NurseryWriteGate::class => \DI\autowire(NurseryWriteGate::class),
    EstablishmentGuard::class => \DI\autowire(EstablishmentGuard::class),
    GatedAction::class => autowire(),
    SectionScopeResolver::class => autowire(),
];

// Sous-modules ajoutés depuis N-002 : leurs propres definitions.php (Contribution, Capacity, Register...).
foreach (glob(__DIR__ . '/*/definitions.php') ?: [] as $file) {
    $definitions = array_merge($definitions, (array) require $file);
}

return $definitions;
