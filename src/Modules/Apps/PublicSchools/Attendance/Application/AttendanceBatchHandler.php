<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/**
 * Gestionnaire `sync.handlers.public.attendance` (T635, API-04) : un élément = l'appel d'une classe pour un jour et une demi-journée,
 * saisi hors ligne. Charge utile minimale (SC-06) : `structure_id`, `date`, `day_part` et seulement les exceptions (`records`) ;
 * tous les autres élèves sont présents par défaut.
 *
 * Le socle Sync ne vérifie que le droit de soumettre un lot : le gestionnaire contrôle lui-même la porte du droit d'usage (application
 * suspendue : 423) et le périmètre de l'enseignant (sa classe seulement). Idempotent par `client_uuid` ; un appel déjà validé et
 * différent n'est jamais écrasé par une synchronisation (409 rendu en conflit) : sa correction passe par la route PUT, avec ses règles.
 */
final class AttendanceBatchHandler implements BatchItemHandler
{
    public function __construct(
        private readonly DailyAttendanceService $attendance,
        private readonly ScopedByTerritory $scope,
        private readonly WriteGate $gate,
    ) {
    }

    public function handle(BatchItem $item, BatchContext $context): ItemResult
    {
        $structureId = self::text($item->payload['structure_id'] ?? null);
        $date = self::text($item->payload['date'] ?? null);
        $dayPart = self::text($item->payload['day_part'] ?? null);
        if ($structureId === null || $date === null || $dayPart === null) {
            return ItemResult::rejected('structure_id, date et day_part sont requis.');
        }

        try {
            $this->gate->assertWritable($context->principal);
            $perimeter = $this->scope->perimeter($context->principal, 'public_schools.attendance.write', $context->establishmentId);
            $this->scope->assertWritable($context->principal, $perimeter, $structureId, 'attendance_session', $structureId . ':' . $date);
            $result = $this->attendance->process(
                $context->tenantId(),
                $context->principal,
                $context->establishmentId,
                $structureId,
                $date,
                $dayPart,
                $item->clientUuid,
                self::records($item->payload['records'] ?? []),
                null,
                false,
            );
        } catch (HttpException $exception) {
            if ($exception->status === 409) {
                return ItemResult::conflict($exception->getMessage());
            }

            return ItemResult::rejected($exception->getMessage(), $exception->status, $exception->errors);
        }

        $data = ['session_id' => $result['session']->id, 'status' => $result['session']->status];

        return $result['outcome'] === DailyAttendanceService::REPLAYED ? ItemResult::duplicate($data) : ItemResult::applied($data);
    }

    /** @return list<array{person_id: string, status: string, arrived_at?: ?string, note?: ?string}> */
    private static function records(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpException::validation(['records' => ['Liste d’exceptions attendue.']]);
        }
        $records = [];
        foreach ($value as $index => $row) {
            if (!is_array($row) || !is_string($row['person_id'] ?? null) || !is_string($row['status'] ?? null)) {
                throw HttpException::validation(['records.' . $index => ['person_id et status sont requis.']]);
            }
            try {
                $mark = new AttendanceMark(
                    trim($row['person_id']),
                    $row['status'],
                    is_string($row['arrived_at'] ?? null) ? $row['arrived_at'] : null,
                    null,
                    is_string($row['note'] ?? null) ? $row['note'] : null,
                );
            } catch (\InvalidArgumentException) {
                throw HttpException::validation(['records.' . $index . '.status' => ['Statut P, A ou R attendu.']]);
            }
            $records[] = [
                'person_id' => $mark->personId,
                'status' => $mark->status,
                'arrived_at' => $mark->arrivedAt,
                'note' => $mark->note,
            ];
        }

        return $records;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
