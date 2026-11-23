<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use Scholaris\Modules\Apps\Higher\Billing\Documents\BillingDocumentTypes;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/**
 * Garde d'émission du quitus financier DOC-30 (RG-FIN-6) : refusé tant que le solde de l'étudiant dans l'établissement n'est
 * pas nul, quelle que soit la voie d'émission (API, lot, demande, contrat). Enregistrée sous `documents.issuance_guards.higher_billing`.
 */
final class QuitusGuard implements IssuanceGuard
{
    public function __construct(private readonly DebtorDirectory $debtors)
    {
    }

    public function refusal(IssuanceContext $context): ?string
    {
        if ($context->type !== BillingDocumentTypes::socleCode(BillingDocumentTypes::QUITUS)) {
            return null;
        }
        $balance = $this->debtors->balanceXaf($context->tenantId, $context->establishmentId, $context->subjectPersonId);
        if ($balance > 0) {
            return sprintf('Quitus financier refusé : le solde n\'est pas nul (%d FCFA restant dus, RG-FIN-6).', $balance);
        }

        return null;
    }
}
