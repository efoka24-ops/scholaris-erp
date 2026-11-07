<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Modules\Apps\Nursery\Development\Http\ObservationController;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire `sync.handlers.nursery.observation` du point d'entrée commun `POST /sync/batches` : une observation prise hors ligne.
 * Droit et section contrôlés ici ; idempotent sur `client_uuid` (rejeu = `duplicate`). Un niveau hors des codes paramétrés est rejeté (422).
 */
final class ObservationBatchHandler implements BatchItemHandler
{
    public const KIND = 'nursery.observation';

    public function __construct(private readonly ObservationService $observations, private readonly Access $access)
    {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $perimeter = $this->access->require($context->principal, $context->establishmentId, ['nursery.dev.observe']);
        $input = new Input($item->payload);
        ObservationController::fields($input);
        $result = $this->observations->record($context->principal, $perimeter, $input->done() + ['client_uuid' => $item->clientUuid]);

        return $result['duplicate'] ? ItemResult::duplicate(['observation' => $result['observation']]) : ItemResult::applied(['observation' => $result['observation']]);
    }
}
