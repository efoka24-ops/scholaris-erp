<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\UnitCreditRecorder;

/** UE capitalisées reprises (UnitCreditRecorder) dans hi_unit_credits, avec le lot de reprise en `jury_id`. */
final class UnitCreditImport implements UnitCreditRecorder
{
    private const TABLE = 'hi_unit_credits';

    public function __construct(
        private readonly Store $store,
        private readonly AcademicClock $clock,
    ) {
    }

    public function record(string $tenantId, string $establishmentId, string $batchId, string $personId, string $programId, string $levelId, string $yearLabel, string $unitCode, ?string $unitTitle, int $credits, ?float $average): bool
    {
        $where = ['person_id' => $personId, 'program_id' => $programId, 'academic_year_id' => $yearLabel, 'unit_code' => $unitCode];
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
            return false;
        }
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
            'id' => (string) new Ulid(), 'level_id' => $levelId, 'period_id' => self::PERIOD,
            'unit_title' => $unitTitle === null ? null : mb_substr($unitTitle, 0, 160), 'credits' => $credits, 'average' => $average,
            'letter' => null, 'unit_status' => 'acquired', 'exam_session' => self::SESSION, 'jury_id' => $batchId, 'recorded_at' => $this->clock->now(),
        ]);

        return true;
    }

    public function revoke(string $tenantId, string $establishmentId, string $batchId): int
    {
        return $this->store->delete(self::TABLE, $tenantId, $establishmentId, ['jury_id' => $batchId]);
    }
}
