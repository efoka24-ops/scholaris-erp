<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Inspection\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire `sync.handlers.public.inspection_visit` (EX-INS-02 : grille remplie hors ligne sur téléphone) : un élément = le rapport
 * d'une visite planifiée (`visit_id`) ou d'une visite faite sans planning (création à la volée). Mêmes règles que la route : porte du
 * droit d'usage, périmètre de l'inspecteur, idempotence par `client_uuid`, conflit (409) sans écrasement d'un rapport existant.
 */
final class VisitBatchHandler implements BatchItemHandler
{
    public function __construct(private readonly InspectionService $inspection, private readonly WriteGate $gate)
    {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $payload = $item->payload + ['client_uuid' => $item->clientUuid];
        try {
            $this->gate->assertWritable($context->principal);
            $visitId = $payload['visit_id'] ?? null;
            $result = is_string($visitId) && $visitId !== ''
                ? $this->inspection->report($context->principal, $context->establishmentId, $visitId, $payload)
                : $this->inspection->recordUnplanned($context->principal, $context->establishmentId, $payload);
        } catch (HttpException $exception) {
            if ($exception->status === 409) {
                return ItemResult::conflict($exception->getMessage());
            }

            return ItemResult::rejected($exception->getMessage(), $exception->status, $exception->errors);
        }
        $data = ['visit_id' => $result['id'], 'state' => $result['state']];

        return ($result['replayed'] ?? false) === true ? ItemResult::duplicate($data) : ItemResult::applied($data);
    }
}
