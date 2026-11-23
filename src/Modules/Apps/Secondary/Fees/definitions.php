<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\Fees\Application\DocumentHoldGuard;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeesFamilyInvoices;
use Scholaris\Modules\Apps\Secondary\Fees\Application\SecondaryFeeGuard;
use Scholaris\Modules\Apps\Secondary\Fees\Console\FeeNoticesCommand;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;

/**
 * Liaisons des frais du secondaire : natures ajoutées au socle, garde de création des frais (catalogue public, RG-FIN-2 ; frais
 * d'examen jamais facturés, RG-FIN-3) et garde de remise des documents pour impayé [fees_private] (RG-DOC-4). Clé de garde de
 * documents propre aux frais (`secondary_fees`) pour ne pas écraser celle du sous-module Documents (fusion par array_merge).
 * Factures ouvertes d'un élève pour le portail parent (Portals\Contracts\FamilyInvoices).
 */
return [
    'billing.fee_kinds.secondary' => FeesDefaults::KINDS,
    'billing.fee_item_guards.secondary' => [SecondaryFeeGuard::class],
    'documents.issuance_guards.secondary_fees' => [DocumentHoldGuard::class],
    'secondary.family_invoices.fees' => [FeesFamilyInvoices::class],
    'console.command.' . FeeNoticesCommand::NAME => autowire(FeeNoticesCommand::class),
];
