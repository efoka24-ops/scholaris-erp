<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

/**
 * Effectifs d'une classe par sexe et par âge, avec l'alerte d'effectif (EX-CLA-03). Le seuil est le paramètre
 * `public_schools.class_size.alert` (défaut 60) : l'alerte se déclenche quand l'effectif le dépasse (61 élèves pour un seuil de 60).
 */
final class ClassHeadcountService
{
    private const DEFAULT_THRESHOLD = 60;

    public function __construct(
        private readonly ClassRoster $classes,
        private readonly PersonDemographics $demographics,
        private readonly SettingsReader $settings,
    ) {
    }

    public function threshold(string $tenantId, string $establishmentId): int
    {
        $threshold = $this->settings->value($tenantId, $establishmentId, 'public_schools.class_size.alert', self::DEFAULT_THRESHOLD);

        return is_int($threshold) && $threshold > 0 ? $threshold : self::DEFAULT_THRESHOLD;
    }

    /**
     * @return array{date: string, total: int, by_sex: array{female: int, male: int, unknown: int}, by_age: array<int, int>, age_unknown: int, threshold: int, alert: bool}
     */
    public function of(string $tenantId, string $establishmentId, string $structureId, string $date): array
    {
        $ids = $this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $date);
        $details = $this->demographics->ofPersons($tenantId, $ids);
        $bySex = ['female' => 0, 'male' => 0, 'unknown' => 0];
        $byAge = [];
        $ageUnknown = 0;
        $on = new DateTimeImmutable($date);
        foreach ($ids as $personId) {
            $info = $details[$personId] ?? ['gender' => null, 'birth_date' => null];
            $gender = $info['gender'];
            ++$bySex[$gender === 'female' || $gender === 'male' ? $gender : 'unknown'];
            $birth = is_string($info['birth_date']) ? date_create_immutable($info['birth_date']) : false;
            if ($birth === false) {
                ++$ageUnknown;

                continue;
            }
            $age = $birth->diff($on)->y;
            $byAge[$age] = ($byAge[$age] ?? 0) + 1;
        }
        ksort($byAge);
        $threshold = $this->threshold($tenantId, $establishmentId);

        return [
            'date' => $date,
            'total' => count($ids),
            'by_sex' => $bySex,
            'by_age' => $byAge,
            'age_unknown' => $ageUnknown,
            'threshold' => $threshold,
            'alert' => count($ids) > $threshold,
        ];
    }
}
