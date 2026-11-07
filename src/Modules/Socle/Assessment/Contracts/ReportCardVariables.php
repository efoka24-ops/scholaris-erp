<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Variables de bulletin d'une application (SS5, PS5), enregistrée sous `assessment.report_card_variables.<application>`.
 * Seules les variables dont le nom commence par `<application>_` (ex. `secondary_conduct` sous la clé `.secondary`) sont
 * retenues ; le préfixe d'origine `primary_` reste accepté quelle que soit la clé (compatibilité).
 */
interface ReportCardVariables
{
    /** @return array<string, scalar|null> */
    public function for(string $tenantId, string $establishmentId, string $councilId, string $personId): array;
}
