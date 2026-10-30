<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Census\Application;

use DateTimeImmutable;
use Scholaris\Modules\Apps\PublicSchools\Contracts\CensusSectionProvider;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Section « effectifs et encadrement » du questionnaire (EX-STA-01) : élèves par niveau et sexe, par âge au 31 décembre de l'année de
 * rentrée, classes, plus grand effectif d'une classe, enseignants. Effectifs seulement, aucun nom. Contrôles (EX-STA-02) : total par âge
 * égal au total par niveau (bloquant), classe au-delà du plafond `public_schools.class_size.alert` (à justifier ; valeur de départ 60 :
 * arrêté conjoint du 14 août 2026, art. 14), école sans enseignant (à justifier).
 */
final class EnrolmentCensusSection implements CensusSectionProvider
{
    public const DEFAULT_CLASS_CEILING = 60;

    public function __construct(
        private readonly ClassRoster $classes,
        private readonly PupilRegistry $pupils,
        private readonly PersonDirectory $people,
        private readonly StaffDirectory $staff,
        private readonly CurrentPeriod $periods,
        private readonly SettingsReader $settings,
        private readonly CensusClock $clock,
    ) {
    }

    public function sectionKey(): string
    {
        return 'enrolment';
    }

    public function title(string $locale): string
    {
        return $locale === 'en' ? 'Enrolment and staff' : 'Effectifs et encadrement';
    }

    public function values(string $tenantId, string $establishmentId, string $yearLabel): array
    {
        $today = $this->clock->today();
        $period = $this->periods->at($tenantId, $establishmentId, $today);
        $values = ['pupils.total' => 0, 'classes.count' => 0, 'pupils.max_per_class' => 0, 'teachers.count' => count($this->staff->teacherIdsIn($tenantId, $establishmentId))];
        if ($period === null) {
            return $values;
        }
        $reference = new DateTimeImmutable(substr($yearLabel, 0, 4) . '-12-31');
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId) as $class) {
            $ids = $this->classes->pupilIdsIn($tenantId, $establishmentId, $class->structureId, $today);
            ++$values['classes.count'];
            $values['pupils.max_per_class'] = max($values['pupils.max_per_class'], count($ids));
            $level = strtolower($class->levelCodes[0] ?? 'unknown');
            $genders = [];
            foreach ($this->people->findMany($tenantId, $ids) as $person) {
                $genders[$person->id] = $person->gender;
            }
            foreach ($ids as $id) {
                $sex = in_array($genders[$id] ?? null, ['female', 'F', 'f'], true) ? 'girls' : 'boys';
                $values['level.' . $level . '.' . $sex] = (int) ($values['level.' . $level . '.' . $sex] ?? 0) + 1;
                $birth = $this->pupils->find($tenantId, $id)?->birthDate;
                $age = $birth === null ? 'unknown' : (string) (new DateTimeImmutable($birth))->diff($reference)->y;
                $values['age.' . $age] = (int) ($values['age.' . $age] ?? 0) + 1;
                ++$values['pupils.total'];
            }
        }
        ksort($values);

        return $values;
    }

    public function controls(string $tenantId, string $establishmentId, string $yearLabel, array $values): array
    {
        $byLevel = 0;
        $byAge = 0;
        foreach ($values as $field => $value) {
            if (str_starts_with($field, 'level.')) {
                $byLevel += (int) $value;
            } elseif (str_starts_with($field, 'age.')) {
                $byAge += (int) $value;
            }
        }
        $anomalies = [];
        if ($byLevel !== $byAge || $byLevel !== (int) ($values['pupils.total'] ?? 0)) {
            $anomalies[] = 'enrolment.age_total_mismatch';
        }
        $ceiling = $this->settings->value($tenantId, $establishmentId, 'public_schools.class_size.alert', self::DEFAULT_CLASS_CEILING);
        if ((int) ($values['pupils.max_per_class'] ?? 0) > (is_numeric($ceiling) ? (int) $ceiling : self::DEFAULT_CLASS_CEILING)) {
            $anomalies[] = 'enrolment.class_over_ceiling';
        }
        if ((int) ($values['pupils.total'] ?? 0) > 0 && (int) ($values['teachers.count'] ?? 0) === 0) {
            $anomalies[] = 'enrolment.no_teacher';
        }

        return $anomalies;
    }
}
