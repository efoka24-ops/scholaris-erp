<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Application;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/**
 * Classes de l'année (contrat Setup ClassProfiles) et élèves inscrits dans chacune (contrat People StructureEnrollments : inscriptions
 * actives ou sous réserve de l'année). Base commune des objectifs et des rapports ; jamais exposée telle quelle au réseau.
 */
final class Roster
{
    /** @param list<array{profile: ClassProfileInfo, persons: list<string>}> $classes */
    private function __construct(public readonly array $classes)
    {
    }

    public static function load(ClassProfiles $profiles, StructureEnrollments $enrollments, string $tenantId, string $establishmentId, string $academicYearId): self
    {
        $classes = [];
        foreach ($profiles->forYear($tenantId, $establishmentId, $academicYearId) as $profile) {
            $persons = [];
            foreach ($enrollments->inStructure($tenantId, $profile->structureId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && $enrollment->academicYearId === $academicYearId
                    && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                    $persons[$enrollment->personId] = true;
                }
            }
            $classes[] = ['profile' => $profile, 'persons' => array_map('strval', array_keys($persons))];
        }

        return new self($classes);
    }

    /** @param list<string> $structureIds */
    public function only(array $structureIds): self
    {
        return new self(array_values(array_filter($this->classes, static fn (array $c): bool => in_array($c['profile']->structureId, $structureIds, true))));
    }

    /** @return list<string> élèves de toutes les classes, sans doublon */
    public function persons(): array
    {
        $all = [];
        foreach ($this->classes as $class) {
            foreach ($class['persons'] as $person) {
                $all[$person] = true;
            }
        }

        return array_map('strval', array_keys($all));
    }

    /** @return list<string> */
    public function structureIds(): array
    {
        return array_map(static fn (array $c): string => $c['profile']->structureId, $this->classes);
    }
}
