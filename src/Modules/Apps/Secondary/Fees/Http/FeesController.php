<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeesMode;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FeesSettings;
use Scholaris\Modules\Apps\Secondary\Fees\Application\PrivateFeesSetup;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Vue d'ensemble des frais (noyau) et préparation des frais du privé [fees_private]. Lecture : droits de lecture des frais ;
 * préparation : `secondary.billing.manage` (intendant) sur tout l'établissement.
 */
final class FeesController extends Controller
{
    public const READ = ['secondary.fees.read', 'secondary.finance.read', 'secondary.billing.manage', 'secondary.fees.catalog', 'secondary.fees.validate'];
    /** Aperçu du mode des frais : aussi la tenue du catalogue (secondary.profile.manage, C-117) et le caissier (secondary.billing.cash, FIX-SEC). */
    public const OVERVIEW = [...self::READ, 'secondary.profile.manage', 'secondary.billing.cash'];
    public const MANAGE = ['secondary.billing.manage'];
    private const SOCLE_KINDS = ['registration', 'tuition', 'other'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly FeesMode $mode,
        private readonly FeesSettings $settings,
        private readonly PrivateFeesSetup $setup,
    ) {
        parent::__construct($responder, $access, $store);
    }

    /** Mode (privé, catalogue public, aucun), natures, natures refusées et paramètres de Billing en vigueur, tous « à confirmer ». */
    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, self::OVERVIEW);
        $tenantId = $principal->tenantId;

        return $this->json([
            'mode' => $this->mode->of($tenantId, $establishmentId) ?? FeesMode::NONE,
            'kinds' => [...self::SOCLE_KINDS, ...FeesDefaults::KINDS],
            'refused_kinds' => $this->settings->refusedKinds($tenantId, $establishmentId),
            'blocked_document_types' => $this->settings->blockedDocumentTypes($tenantId, $establishmentId),
            'allocation_rule' => $this->settings->value($tenantId, $establishmentId, 'billing.allocation_rule', ''),
            'allocation_kind_order' => $this->settings->value($tenantId, $establishmentId, 'billing.allocation_kind_order', null),
            'allocation_sibling_order' => $this->settings->value($tenantId, $establishmentId, 'billing.allocation_sibling_order', null),
            'reminder_offsets' => $this->settings->value($tenantId, $establishmentId, 'billing.reminder_offsets', null),
            'blocks' => $this->settings->value($tenantId, $establishmentId, 'billing.blocks', null),
            'presets' => FeesDefaults::PRIVATE_PRESETS,
            'confirmation' => FeesDefaults::CONFIRMATION,
        ]);
    }

    /** Pose les préréglages du privé (jamais d'écrasement) ; rejouable sans effet. */
    public function setupPrivate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'secondary_fees');
        $result = $this->setup->apply($principal->tenantId, $establishmentId, $principal->userId);
        $this->access->audit($principal, 'secondary.fees.private_setup', 'secondary_fees', $establishmentId, null, ['applied' => array_keys(array_filter($result, static fn (array $r): bool => $r['applied']))]);

        return $this->json(['presets' => $result, 'confirmation' => FeesDefaults::CONFIRMATION]);
    }
}
