<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/** Émission demandée, telle que la voit une garde (S18). `$via` : api, batch, request ou contract. */
final class IssuanceContext
{
    /** @param array<string, scalar|null> $data */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $type,
        public readonly string $subjectPersonId,
        public readonly array $data,
        public readonly string $locale,
        public readonly string $issuedBy,
        public readonly string $via,
        public readonly ?string $orgUnitId = null,
    ) {
    }
}
