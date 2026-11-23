<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\FeeItemDraft;
use Scholaris\Modules\Socle\Billing\Contracts\FeeItemGuard;

/**
 * Garde de création et de modification des frais du socle (`POST/PATCH /fee-items`) pour les établissements du secondaire,
 * enregistrée sous `billing.fee_item_guards.secondary` (SS8) :
 *  - tout établissement du secondaire : une nature « jamais facturée » (frais d'examen payés sur cartescolaire.cm, RG-FIN-3) est
 *    refusée ;
 *  - [fees_public_catalog] : un frais actif absent du catalogue autorisé (nature, code, plafond) est refusé (RG-FIN-2, TST-06) ;
 *    une demande d'ajout est créée (ou retrouvée si elle attend déjà) et journalisée. Désactiver un frais reste toujours permis.
 * Un établissement qui n'est pas du secondaire est ignoré.
 */
final class SecondaryFeeGuard implements FeeItemGuard
{
    /** Auteur inscrit sur une demande créée par la garde : le contrat FeeItemDraft ne porte pas l'utilisateur (demande au socle). */
    public const GUARD_ACTOR = 'fee_item_guard';

    public function __construct(
        private readonly FeesMode $mode,
        private readonly FeesSettings $settings,
        private readonly FeeCatalog $catalog,
        private readonly AuditLog $audit,
    ) {
    }

    public function refusal(FeeItemDraft $draft): ?string
    {
        $mode = $this->mode->of($draft->tenantId, $draft->establishmentId);
        if ($mode === null) {
            return null;
        }
        if (in_array($draft->kind, $this->settings->refusedKinds($draft->tenantId, $draft->establishmentId), true)) {
            return sprintf('La nature « %s » n\'est jamais facturée par l\'établissement : les frais d\'examen officiels se paient sur cartescolaire.cm (RG-FIN-3).', $draft->kind);
        }
        if ($mode !== FeesMode::PUBLIC_CATALOG || !$draft->active) {
            return null;
        }
        if ($this->catalog->authorizing($draft->tenantId, $draft->establishmentId, $draft->kind, $draft->code, $draft->amountXaf) !== null) {
            return null;
        }
        [$request, $created] = $this->catalog->request(
            $draft->tenantId,
            $draft->establishmentId,
            $draft->kind,
            $draft->code,
            $draft->name,
            $draft->amountXaf,
            'Demande créée automatiquement : frais refusé hors catalogue.',
            'guard',
            self::GUARD_ACTOR,
        );
        $this->audit->append($draft->tenantId, self::GUARD_ACTOR, 'secondary.fee.refused_out_of_catalog', 'secondary_fee_request', (string) $request['id'], null, [
            'fee_kind' => $draft->kind, 'label' => $draft->name, 'amount' => $draft->amountXaf, 'establishment_id' => $draft->establishmentId, 'request_created' => $created,
        ]);

        return sprintf(
            'Frais absent du catalogue des frais autorisés de l\'établissement public (RG-FIN-2). Une demande d\'ajout est adressée au client (référence %s).',
            (string) $request['id'],
        );
    }
}
