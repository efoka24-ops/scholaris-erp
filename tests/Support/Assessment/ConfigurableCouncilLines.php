<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\CouncilLineProvider;

/** Fournisseur de lignes de conseil de test (SS6) : valeurs propres réglables par élève. */
final class ConfigurableCouncilLines implements CouncilLineProvider
{
    /** @var array<string, array{conduct?: mixed, appraisal?: mixed, extras?: array<string, mixed>}> */
    public static array $lines = [];

    public function prefill(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array
    {
        return array_intersect_key(self::$lines, array_flip($personIds));
    }
}
