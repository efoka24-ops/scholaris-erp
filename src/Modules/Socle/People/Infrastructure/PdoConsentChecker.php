<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRefusals;

/** Consentement valable = dernière décision enregistrée pour cette finalité, non révoquée ; refus explicite = dernière décision révoquée. */
final class PdoConsentChecker implements ConsentChecker, ConsentRefusals
{
    public function __construct(private readonly PdoStore $store)
    {
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        $last = $this->latest($tenantId, $personId, $purpose);

        return $last !== null && $last['revoked_at'] === null;
    }

    public function hasRevoked(string $tenantId, string $personId, string $purpose): bool
    {
        $last = $this->latest($tenantId, $personId, $purpose);

        return $last !== null && $last['revoked_at'] !== null;
    }

    /** @return array<string, mixed>|null dernière décision enregistrée pour cette finalité */
    private function latest(string $tenantId, string $personId, string $purpose): ?array
    {
        $rows = $this->store->rows(
            'SELECT revoked_at FROM consents WHERE tenant_id = ? AND person_id = ? AND purpose = ? ORDER BY granted_at DESC, id DESC LIMIT 1',
            [$tenantId, $personId, $purpose],
        );

        return $rows[0] ?? null;
    }
}
