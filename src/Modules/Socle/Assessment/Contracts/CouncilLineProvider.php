<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

interface CouncilLineProvider
{
    /**
     * @param list<string> $personIds
     * @return array<string, array{conduct?: mixed, appraisal?: mixed, extras?: array<string, mixed>}>
     */
    public function prefill(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array;
}
