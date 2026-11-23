<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotDraft;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableConflict;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableWriter;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\Input;
use Scholaris\Modules\Socle\Scheduling\Http\Requests\SlotRules;

/** Contrat d'écriture des créneaux (D11) : mêmes validations et détection de conflits que l'API, sans HTTP. */
final class SlotWriter implements TimetableWriter
{
    public function __construct(private readonly SlotService $slots)
    {
    }

    public function create(string $tenantId, string $establishmentId, SlotDraft $draft, string $actorId): string
    {
        $data = Input::validate($draft->toArray(), SlotRules::rules(), SlotRules::REQUIRED, SlotRules::NULLABLE);
        try {
            return (string) $this->slots->create($tenantId, $establishmentId, $data, $actorId)['id'];
        } catch (SchedulingConflict $e) {
            throw self::conflict($e->conflicts);
        }
    }

    public function update(string $tenantId, string $establishmentId, string $slotId, array $changes): void
    {
        $data = Input::validate($changes, SlotRules::rules(), [], SlotRules::NULLABLE, true);
        try {
            $this->slots->update($tenantId, $establishmentId, $slotId, $data);
        } catch (SchedulingConflict $e) {
            throw self::conflict($e->conflicts);
        }
    }

    public function delete(string $tenantId, string $establishmentId, string $slotId): void
    {
        $this->slots->delete($tenantId, $establishmentId, $slotId);
    }

    public function check(string $tenantId, string $establishmentId, SlotDraft $draft, ?string $ignoreSlotId = null): array
    {
        $data = Input::validate($draft->toArray(), SlotRules::rules(), SlotRules::REQUIRED, SlotRules::NULLABLE);

        return array_map(static fn (Conflict $c): array => $c->toArray(), $this->slots->check($tenantId, $establishmentId, $data, $ignoreSlotId));
    }

    /** @param list<Conflict> $conflicts */
    private static function conflict(array $conflicts): TimetableConflict
    {
        return new TimetableConflict(array_map(static fn (Conflict $c): array => $c->toArray(), $conflicts));
    }
}
