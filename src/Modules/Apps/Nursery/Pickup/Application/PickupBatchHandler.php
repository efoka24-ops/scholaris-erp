<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire `sync.handlers.nursery.pickup` du point d'entrée commun `POST /sync/batches` (appareil signé, MQ-17 : pas de route
 * propre). Éléments : `handover`, `refusal`, `hold`. Le droit est contrôlé ICI (`nursery.pickup.handover` sur la section de l'enfant),
 * l'idempotence repose sur `client_uuid` : un rejeu rend `duplicate`, jamais un second événement. Une remise dont l'autorisation est
 * devenue invalide est ENREGISTRÉE (l'enfant est parti) marquée `stale_authorization`, avec alerte ; un refus est enregistré et
 * alerté. Les deux rendent `applied` avec le détail dans `data`.
 */
final class PickupBatchHandler implements BatchItemHandler
{
    public const KIND = 'nursery.pickup';

    public function __construct(private readonly HandoverService $handovers, private readonly Access $access)
    {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $perimeter = $this->access->require($context->principal, $context->establishmentId, ['nursery.pickup.handover']);
        $payload = $item->payload;
        $type = (string) ($payload['type'] ?? '');
        unset($payload['type']);
        $input = new Input($payload);
        $input->reference('learner_id');
        $input->date('school_day', false);
        $input->string('occurred_at', 32, false);
        match ($type) {
            'handover' => $this->handoverFields($input),
            'refusal' => $input->string('declared_name', 160, false),
            'hold' => $input->string('remark', 500),
            default => throw HttpException::validation(['type' => ['Type d’élément inconnu : handover, refusal ou hold.']]),
        };
        $data = $input->done() + ['origin' => 'offline', 'client_uuid' => $item->clientUuid];

        if ($type === 'hold') {
            return ItemResult::applied(['event' => $this->handovers->hold($context->principal, $perimeter, $data)]);
        }
        $result = $type === 'handover' ? $this->handovers->handover($context->principal, $perimeter, $data) : $this->handovers->refusal($context->principal, $perimeter, $data);
        $data = ['status' => $result['status'], 'reason' => $result['reason'], 'stale_authorization' => $result['stale_authorization'], 'event' => $result['event']];

        return $result['duplicate'] ? ItemResult::duplicate($data) : ItemResult::applied($data);
    }

    private function handoverFields(Input $input): void
    {
        $input->reference('pickup_id', false);
        $input->string('code', 20, false);
        $input->string('declared_name', 160, false);
        $input->reference('pickup_person_id', false);
    }
}
