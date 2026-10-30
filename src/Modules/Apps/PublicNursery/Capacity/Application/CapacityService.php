<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Capacity\Application;

use Scholaris\Modules\Apps\PublicNursery\Support\SectionScope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Effectif des classes de la maternelle publique contre le plafond `public_nursery.class.max_children` (valeur de départ 30, arrêté
 * conjoint du 14 août 2026, article 14). Un dépassement produit une ALERTE (dédoublement en mi-temps ou nouvelle division dans les deux
 * semaines, déclaration des effectifs réels) : l'inscription n'est jamais bloquée. Sans plafond paramétré, aucune alerte n'est émise.
 */
final class CapacityService
{
    public const MAX_CHILDREN = 'public_nursery.class.max_children';

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly SettingsReader $settings,
    ) {
    }

    /** @return array{max_children: ?int, classes: list<array<string, mixed>>, over_capacity: int} */
    public function report(string $tenantId, string $establishmentId, SectionScope $scope): array
    {
        $max = $this->settings->value($tenantId, $establishmentId, self::MAX_CHILDREN);
        $max = is_int($max) && $max > 0 ? $max : null;
        $counts = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $personId) {
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId !== null
                    && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                    $counts[$enrollment->structureId] = ($counts[$enrollment->structureId] ?? 0) + 1;
                    break;
                }
            }
        }
        $classes = [];
        $over = 0;
        foreach ($counts as $structureId => $count) {
            $structureId = (string) $structureId;
            if (!$scope->contains($structureId)) {
                continue;
            }
            $info = $this->tree->structure($tenantId, $establishmentId, $structureId);
            $alert = $max !== null && $count > $max;
            $over += $alert ? 1 : 0;
            $classes[] = [
                'section_id' => $structureId, 'name' => $info !== null ? $info->name : $structureId, 'enrolled' => $count, 'max_children' => $max,
                'over_capacity' => $alert, 'excess' => $alert ? $count - $max : 0,
            ];
        }
        usort($classes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return ['max_children' => $max, 'classes' => $classes, 'over_capacity' => $over];
    }
}
