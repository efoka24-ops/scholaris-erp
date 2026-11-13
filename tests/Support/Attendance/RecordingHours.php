<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\Staff\Contracts\TaughtHoursRecorder;

/** Doublure de TaughtHoursRecorder : idempotente par (enseignant, créneau, date), comme l'implémentation de Staff. */
final class RecordingHours implements TaughtHoursRecorder
{
    /** @var array<string, array{tenant: string, establishment: string, staff: string, slot: string, date: string, minutes: int}> */
    public array $lines = [];

    public int $calls = 0;

    public bool $fail = false;

    public function record(string $tenantId, string $establishmentId, string $staffId, string $slotId, string $date, int $minutes): string
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('Staff indisponible.');
        }
        $key = $tenantId . '|' . $staffId . '|' . $slotId . '|' . $date;
        $this->lines[$key] ??= ['tenant' => $tenantId, 'establishment' => $establishmentId, 'staff' => $staffId, 'slot' => $slotId, 'date' => $date, 'minutes' => $minutes];

        return 'h-' . md5($key);
    }
}
