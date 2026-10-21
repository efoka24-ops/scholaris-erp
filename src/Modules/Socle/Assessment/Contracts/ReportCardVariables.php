<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

interface ReportCardVariables
{
    /** @return array<string, scalar|null> Toutes les variables fournies doivent commencer par primary_. */
    public function for(string $tenantId, string $establishmentId, string $councilId, string $personId): array;
}
