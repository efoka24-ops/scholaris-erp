<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres `secondary.fees.*` (héritage plateforme, client, établissement), valeurs de départ « à confirmer » (FeesDefaults). */
final class FeesSettings
{
    public function __construct(private readonly SettingsReader $settings)
    {
    }

    /** @return list<string> natures jamais facturées par l'établissement (RG-FIN-3) */
    public function refusedKinds(string $tenantId, string $establishmentId): array
    {
        return $this->codes($tenantId, $establishmentId, 'secondary.fees.refused_kinds', FeesDefaults::REFUSED_KINDS);
    }

    /** @return list<string> types de documents dont la remise attend le règlement [fees_private] (RG-DOC-4) */
    public function blockedDocumentTypes(string $tenantId, string $establishmentId): array
    {
        return $this->codes($tenantId, $establishmentId, 'secondary.fees.blocked_document_types', FeesDefaults::BLOCKED_DOCUMENT_TYPES);
    }

    public function planMaxInstallments(string $tenantId, string $establishmentId): int
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'secondary.fees.plan_max_installments', null);

        return is_int($raw) && $raw >= 1 && $raw <= FeesDefaults::PLAN_MAX_INSTALLMENTS * 4 ? $raw : FeesDefaults::PLAN_MAX_INSTALLMENTS;
    }

    /** Valeur effective d'un paramètre quelconque (vue d'ensemble des frais). */
    public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed
    {
        return $this->settings->value($tenantId, $establishmentId, $key, $default);
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function codes(string $tenantId, string $establishmentId, string $key, array $default): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, $key, null);
        if (!is_array($raw)) {
            return $default;
        }

        return array_values(array_filter($raw, static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-z][a-z0-9_.-]{0,59}$/', $v) === 1));
    }
}
