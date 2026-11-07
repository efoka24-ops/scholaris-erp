<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;

/** Consentement valable = dernière décision enregistrée pour cette finalité, non révoquée. */
final class PdoConsentChecker implements ConsentChecker
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        $rows = $this->store->rows(
            'SELECT revoked_at FROM consents WHERE tenant_id = ? AND person_id = ? AND purpose = ? ORDER BY granted_at DESC, id DESC LIMIT 1',
            [$tenantId, $personId, $purpose],
        );

        return $rows !== [] && $rows[0]['revoked_at'] === null;
    }
}
