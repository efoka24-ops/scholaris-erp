<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\JuryService;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;

/** Lecture des jurys et décisions annuelles de l'IPES (tables hi_juries, hi_year_decisions). */
final class PdoDeliberationRecords implements DeliberationRecords
{
    public const SIGNED = ['signed', 'published'];

    public function __construct(private readonly Store $store)
    {
    }

    public function minutesSigned(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session = 'normal'): bool
    {
        $jury = $this->store->select(JuryService::TABLE, $tenantId, $establishmentId, ['structure_id' => $structureId, 'period_id' => $periodId, 'exam_session' => $session], 'id', 1)[0] ?? null;

        return $jury !== null && in_array($jury['status'], self::SIGNED, true);
    }

    public function yearDecision(string $tenantId, string $establishmentId, string $personId, string $programId, string $academicYearId): ?array
    {
        $row = $this->store->select('hi_year_decisions', $tenantId, $establishmentId, ['person_id' => $personId, 'program_id' => $programId, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'decision_code' => (string) $row['decision_code'], 'decision_label' => (string) $row['decision_label'], 'credits_earned' => (int) $row['credits_earned'],
            'cumulative_credits' => (int) $row['cumulative_credits'], 'diploma_file' => (int) $row['diploma_file'] === 1,
            'mention' => $row['mention'] === null ? null : (string) $row['mention'],
        ];
    }
}
