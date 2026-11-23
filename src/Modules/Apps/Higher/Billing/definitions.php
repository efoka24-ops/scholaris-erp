<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Billing\Application\AccountService;
use Scholaris\Modules\Apps\Higher\Billing\Application\FinancialHoldPolicy;
use Scholaris\Modules\Apps\Higher\Billing\Application\QuitusGuard;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentPayments;
use Scholaris\Modules\Apps\Higher\Billing\Documents\BillingDocumentTypes;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;

/** Liaisons du module FIN de l'IPES : types de frais (S10), contrats Higher, garde du quitus (S18), types DOC-27 à DOC-30. */
return [
    // Types de frais propres à l'application, ajoutés à ceux du socle (registration, tuition, other).
    'billing.fee_kinds.higher' => HigherFees::SOCLE_KINDS,

    FinancialHolds::class => get(FinancialHoldPolicy::class),
    StudentAccounts::class => get(AccountService::class),
    StudentPayments::class => get(AccountService::class),
    AccountService::class => autowire(),

    // Quitus financier refusé si le solde n'est pas nul (RG-FIN-6), quelle que soit la voie d'émission.
    'documents.issuance_guards.higher_billing' => [QuitusGuard::class],
    // Documents financiers DOC-27 à DOC-30 au catalogue des documents de l'IPES.
    'higher.document_types.billing' => [BillingDocumentTypes::class],
];
