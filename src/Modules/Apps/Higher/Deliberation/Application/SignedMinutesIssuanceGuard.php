<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure\PdoDeliberationRecords;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/**
 * RG-DOC-3 (S18) : relevés (DOC-09, DOC-10) et liste des résultats (DOC-16) émis seulement après signature du PV par le président
 * (variable `jury_ref` = jury Higher signé ou publié) ; attestations de réussite et de fin de formation (DOC-11, DOC-12) seulement
 * après une décision annuelle enregistrée sur PV signé. Enregistrée sous `documents.issuance_guards.higher_deliberation`.
 */
final class SignedMinutesIssuanceGuard implements IssuanceGuard
{
    public function __construct(private readonly Store $store)
    {
    }

    public function refusal(IssuanceContext $context): ?string
    {
        if (in_array($context->type, DeliberationDocumentTypes::REQUIRE_SIGNED_MINUTES, true)) {
            $juryId = $context->data['jury_ref'] ?? null;
            $jury = is_string($juryId) && $juryId !== '' ? $this->store->find(JuryService::TABLE, $context->tenantId, $context->establishmentId, $juryId) : null;
            if ($jury === null || !in_array($jury['status'], PdoDeliberationRecords::SIGNED, true)) {
                return 'Relevé ou liste de résultats émis seulement après la signature du procès-verbal de délibération par le président du jury (RG-DOC-3).';
            }
        }
        if (in_array($context->type, DeliberationDocumentTypes::REQUIRE_YEAR_DECISION, true)
            && $this->store->count('hi_year_decisions', $context->tenantId, $context->establishmentId, ['person_id' => $context->subjectPersonId]) === 0) {
            return 'Attestation émise seulement après la délibération annuelle signée (RG-DOC-3).';
        }

        return null;
    }
}
