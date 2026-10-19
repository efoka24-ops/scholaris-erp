<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Socle\Billing\Application\YearBalanceCarryOver;
use Scholaris\Modules\Socle\Billing\Application\YearClosureCheck;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Infrastructure\PdoDebtorDirectory;

/** Liaisons du module Billing : contrat public => implémentation (les services sont résolus par autowiring). */
return [
    DebtorDirectory::class => get(PdoDebtorDirectory::class),
    PdoDebtorDirectory::class => autowire(),
    \Scholaris\Modules\Socle\Billing\Contracts\EstablishmentBalances::class => get(PdoDebtorDirectory::class),
    \Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals::class => autowire(\Scholaris\Modules\Socle\Billing\Infrastructure\PdoInvoiceTotals::class),

    // Contrats d'écriture pour les applications (D4) : factures (inscription, facture libre de candidat, tiers payeur) et paiements.
    \Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer::class => autowire(\Scholaris\Modules\Socle\Billing\Application\InvoiceIssuerService::class),
    \Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester::class => autowire(\Scholaris\Modules\Socle\Billing\Application\PaymentRequesterService::class),
    // Types de frais propres aux applications (S10) : une clé de liste par application, `billing.fee_kinds.<application>`.
    'billing.fee_kinds.socle' => [],

    // Clôture d'année (contrats sortants de l'Organisation) : contrôle des impayés et report des soldes.
    'organisation.closure.checks.billing' => [YearClosureCheck::class],
    'organisation.closure.carry_over.billing' => [YearBalanceCarryOver::class],

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.billing' => \DI\autowire(\Scholaris\Modules\Socle\Billing\Infrastructure\PrivacyExportSource::class),
];
