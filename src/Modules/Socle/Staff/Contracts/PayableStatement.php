<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Relevé mensuel de vacations soumis aux contrôles de payabilité avant son approbation. */
final class PayableStatement
{
    /** @param list<PayableHour> $lines heures du relevé */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $staffId,
        public readonly string $personId,
        /** AAAA-MM */
        public readonly string $period,
        public readonly string $contractId,
        public readonly int $minutesTotal,
        public readonly int $hetdMilli,
        public readonly int $grossAmount,
        public readonly array $lines,
    ) {
    }
}
