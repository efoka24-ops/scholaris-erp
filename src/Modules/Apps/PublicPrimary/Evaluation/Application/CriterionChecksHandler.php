<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Saisie hors ligne des critères (`sync.handlers.public_primary.criterion_checks`, T663) : même chemin que l'API-06 (périmètre de la
 * classe, figement, idempotence par `client_uuid` de l'élément : un rejeu identique rend `duplicate`, un contenu différent `conflict`).
 * La porte d'usage de l'application est contrôlée ici (le socle ne contrôle que le droit de soumettre un lot).
 */
final class CriterionChecksHandler implements BatchItemHandler
{
    public function __construct(private readonly EvaluationService $service, private readonly ApplicationAccess $access)
    {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        if (!$this->access->allowsWrites($context->tenantId(), 'public_primary')) {
            return ItemResult::rejected('Application en lecture seule ou fermée.', 423);
        }
        $unitId = $item->payload['unit_id'] ?? null;
        if (!is_string($unitId) || $unitId === '') {
            return ItemResult::rejected('Unité requise.', 422, ['unit_id' => ['Unité requise.']]);
        }
        try {
            $result = $this->service->saveChecks($context->principal, $context->establishmentId, $unitId, $item->payload, $item->clientUuid);
        } catch (HttpException $refused) {
            if ($refused->status === 409) {
                return ItemResult::conflict($refused->getMessage());
            }
            throw $refused;
        }

        return $result['replayed'] ? ItemResult::duplicate(['applied' => $result['applied']]) : ItemResult::applied(['applied' => $result['applied'], 'mentions' => $result['mentions']]);
    }
}
