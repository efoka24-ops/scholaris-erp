<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Nursery\Billing\Application\BillingService;
use Scholaris\Modules\Apps\Nursery\Billing\Domain\NurseryFees;
use Scholaris\Modules\Apps\Nursery\Billing\Http\BillingController;

return [
    'billing.fee_kinds.nursery' => NurseryFees::KINDS,
    BillingService::class => autowire(),
    BillingController::class => autowire(),
];
