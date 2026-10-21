<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/** Application d'un élément hors ligne d'appel, sans écraser une séance validée différente. */
final class AttendanceBatchHandler implements BatchItemHandler
{
    public function __construct(private readonly DailyAttendanceService $attendance)
    {
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
            $session = $this->attendance->submit(
                $context->tenantId(),
                $context->principal,
                $context->establishmentId,
                $structureId,
                $date,
                $dayPart,
                $item->clientUuid,
                self::records($item->payload['records'] ?? []),
                is_string($item->payload['reason'] ?? null) ? $item->payload['reason'] : null,
                false,
            );
        } catch (HttpException $exception) {
            if ($exception->status === 409) {
                return ItemResult::conflict($exception->getMessage());
            }

            return ItemResult::rejected($exception->getMessage(), $exception->status, $exception->errors);
        }

        return ItemResult::applied(['session_id' => $session->id, 'status' => $session->status]);
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
