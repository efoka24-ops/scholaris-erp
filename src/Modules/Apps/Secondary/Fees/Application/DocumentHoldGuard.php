<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/**
 * Blocage paramétrable de la REMISE de documents pour impayé [fees_private] (EX-FIN-06, RG-DOC-4) : seulement pour les types
 * listés dans `secondary.fees.blocked_document_types` (vide par défaut), seulement si la politique de blocage `documents` du socle
 * (`billing.blocks`) s'applique à l'élève, et jamais si un plan de paiement validé est à jour. Le contenu du document n'est jamais
 * modifié et rien ne touche aux cours ni aux évaluations (RG-FIN-1). Enregistrée sous `documents.issuance_guards.secondary_fees`.
 */
final class DocumentHoldGuard implements IssuanceGuard
{
    public function __construct(
        private readonly FeesMode $mode,
        private readonly FeesSettings $settings,
        private readonly DebtorDirectory $debtors,
        private readonly PaymentPlans $plans,
    ) {
    }

    public function refusal(IssuanceContext $context): ?string
    {
        if ($this->mode->of($context->tenantId, $context->establishmentId) !== FeesMode::PRIVATE) {
            return null;
        }
        if (!in_array($context->type, $this->settings->blockedDocumentTypes($context->tenantId, $context->establishmentId), true)) {
            return null;
        }
        if (!$this->debtors->isBlocked($context->tenantId, $context->establishmentId, $context->subjectPersonId, 'documents')) {
            return null;
        }
        if ($this->plans->hasPlanInGoodStanding($context->tenantId, $context->establishmentId, $context->subjectPersonId)) {
            return null;
        }

        return 'Remise du document retardée : un règlement est attendu (blocage paramétré par l\'établissement). Le contenu du document n\'est pas modifié ; un plan de paiement validé lève ce blocage.';
    }
}
