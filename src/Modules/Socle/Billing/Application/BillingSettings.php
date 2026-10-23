<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Modules\Socle\Billing\Domain\AllocationRule;
use Scholaris\Modules\Socle\Billing\Domain\AllocationRules;
use Scholaris\Modules\Socle\Billing\Domain\KindPriorityRule;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Paramètres du module (clés billing.*), lus avec leur héritage plateforme, client, établissement. */
final class BillingSettings
{
    /** Scopes de blocage paramétrables ; tout autre scope (cours, composition, certificat, reçu...) n'est jamais bloqué. */
    public const BLOCKABLE = ['results', 'documents', 'reinscription'];

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    public function allocationRule(string $tenantId, string $establishmentId): AllocationRule
    {
        $name = (string) $this->settings->value($tenantId, $establishmentId, 'billing.allocation_rule', '');

        return AllocationRules::byName($name, $name === KindPriorityRule::NAME ? $this->allocationKindOrder($tenantId, $establishmentId) : []);
    }

    /** @return list<string> ordre des natures de la règle « kind_priority » (`billing.allocation_kind_order`) ; vide = ordre par défaut */
    public function allocationKindOrder(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.allocation_kind_order', null);
        if (!is_array($raw)) {
            return [];
        }
        $kinds = [];
        foreach ($raw as $kind) {
            if (is_string($kind) && ($kind === KindPriorityRule::WILDCARD || preg_match(FeeKinds::FORMAT, $kind) === 1)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /** Ordre des enfants d'une famille (`billing.allocation_sibling_order`) : « enrolled_first » (défaut) ou « eldest_first ». */
    public function allocationSiblingOrder(string $tenantId, string $establishmentId): string
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.allocation_sibling_order', null);

        return is_string($raw) && in_array($raw, KindPriorityRule::SIBLING_ORDERS, true) ? $raw : KindPriorityRule::SIBLING_ENROLLED_FIRST;
    }

    /** @return array{mode: string, value: int} remise de fratrie accordée à partir du deuxième enfant (0 = désactivée) */
    public function siblingDiscount(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.sibling_discount', null);
        if (!is_array($raw)) {
            return ['mode' => 'fixed', 'value' => 25000];
        }
        $mode = ($raw['mode'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $value = is_int($raw['value'] ?? null) ? max(0, $raw['value']) : 0;

        return ['mode' => $mode, 'value' => $mode === 'percent' ? min(100, $value) : $value];
    }

    /** @return list<int> décalages en jours par rapport à l'échéance (négatif = avant) ; défaut J-7, J+1, J+15 */
    public function reminderOffsets(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.reminder_offsets', null);
        if (!is_array($raw)) {
            return [-7, 1, 15];
        }
        $offsets = [];
        foreach ($raw as $offset) {
            if (is_int($offset) && $offset >= -60 && $offset <= 365) {
                $offsets[] = $offset;
            }
        }

        return array_values(array_unique($offsets));
    }

    /** Les factures impayées de l'année bloquent-elles sa clôture ? Non par défaut : elles sont reportées sur l'année suivante. */
    public function closureBlocksOnUnpaid(string $tenantId, string $establishmentId): bool
    {
        return $this->settings->value($tenantId, $establishmentId, 'billing.closure.block_on_unpaid', false) === true;
    }

    /** Plafond des remises et bourses cumulées, en % de la base visée (S11, `billing.discount_cap_percent`) ; null = aucun plafond. */
    public function discountCapPercent(string $tenantId, string $establishmentId): ?int
    {
        return self::percent($this->settings->value($tenantId, $establishmentId, 'billing.discount_cap_percent', null));
    }

    /** Au-delà de ce % cumulé, une remise attend la validation d'une autre personne (`billing.discount_approval_percent`) ; null = jamais. */
    public function discountApprovalPercent(string $tenantId, string $establishmentId): ?int
    {
        return self::percent($this->settings->value($tenantId, $establishmentId, 'billing.discount_approval_percent', null));
    }

    /** @return list<string>|null cibles de remise permises (`billing.discount_applies_to`, ex. ["tuition"]) ; null = toutes */
    public function discountTargets(string $tenantId, string $establishmentId): ?array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.discount_applies_to', null);
        if (!is_array($raw)) {
            return null;
        }

        return array_values(array_filter($raw, 'is_string'));
    }

    private static function percent(mixed $raw): ?int
    {
        return is_int($raw) && $raw >= 0 && $raw <= 100 ? $raw : null;
    }

    public function receiptCode(string $tenantId, string $establishmentId): ?string
    {
        $code = $this->settings->value($tenantId, $establishmentId, 'billing.receipt_code', null);

        return is_string($code) && preg_match('/^[A-Z0-9]{2,8}$/', $code) === 1 ? $code : null;
    }

    /**
     * @return array{enabled: bool, min_overdue_days: int, min_amount_xaf: int} politique de blocage d'un scope (désactivée par défaut)
     */
    public function blockPolicy(string $tenantId, string $establishmentId, string $scope): array
    {
        $off = ['enabled' => false, 'min_overdue_days' => 0, 'min_amount_xaf' => 1];
        $raw = $this->settings->value($tenantId, $establishmentId, 'billing.blocks', null);
        if (!in_array($scope, self::BLOCKABLE, true) || !is_array($raw) || !is_array($raw[$scope] ?? null)) {
            return $off;
        }
        $policy = $raw[$scope];

        return [
            'enabled' => ($policy['enabled'] ?? false) === true,
            'min_overdue_days' => is_int($policy['min_overdue_days'] ?? null) ? max(0, $policy['min_overdue_days']) : 0,
            'min_amount_xaf' => is_int($policy['min_amount_xaf'] ?? null) ? max(1, $policy['min_amount_xaf']) : 1,
        ];
    }
}
