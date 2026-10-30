<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\ReportCardVariables;

/** Fournisseur de variables de bulletin de test (SS5) : préfixes divers, seuls ceux de l'application sont retenus. */
final class ApplicationReportCardVariables implements ReportCardVariables
{
    public function for(string $tenantId, string $establishmentId, string $councilId, string $personId): array
    {
        return ['secondary_conduct' => 'Bonne', 'primary_legacy' => 'ancien', 'higher_gpa' => 3.2, 'secondaryx' => 'non', 'average' => 99];
    }
}
