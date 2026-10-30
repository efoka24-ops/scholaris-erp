<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Application;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\IdentityKey;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Élèves inscrits (statut actif) de l'établissement, lus par les contrats du socle People en lectures groupées : identité (nom,
 * prénoms), date de naissance, et à la demande niveau de la classe (pour la liste des niveaux d'entrée, EX-CSC-02).
 */
final class Roster
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly PersonDemographics $demographics,
        private readonly OrgUnitTree $tree,
    ) {
    }

    /** @return array<string, array{person_id: string, first_name: string, last_name: string, birth_date: ?string, key_name: string}> */
    public function students(string $tenantId, string $establishmentId): array
    {
        $ids = array_values(array_unique($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId)));
        if ($ids === []) {
            return [];
        }
        $births = $this->demographics->ofPersons($tenantId, $ids);
        $students = [];
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            $students[$person->id] = [
                'person_id' => $person->id,
                'first_name' => $person->firstName,
                'last_name' => $person->lastName,
                'birth_date' => $births[$person->id]['birth_date'] ?? null,
                'key_name' => IdentityKey::name($person->lastName . ' ' . $person->firstName),
            ];
        }
        uasort($students, static fn (array $a, array $b): int => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);

        return $students;
    }

    /**
     * Libellé du niveau de la classe actuelle de chaque élève donné (null si inconnu).
     *
     * @param list<string> $personIds
     * @return array<string, ?string>
     */
    public function levelNames(string $tenantId, string $establishmentId, array $personIds): array
    {
        $cache = [];
        $levels = [];
        foreach ($personIds as $personId) {
            $structureId = null;
            foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
                if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                    $structureId = $enrollment->structureId;
                }
            }
            if ($structureId === null) {
                $levels[$personId] = null;
                continue;
            }
            if (!array_key_exists($structureId, $cache)) {
                $levelId = $this->tree->levelOf($tenantId, $establishmentId, $structureId) ?? $structureId;
                $cache[$structureId] = $this->tree->structure($tenantId, $establishmentId, $levelId)?->name;
            }
            $levels[$personId] = $cache[$structureId];
        }

        return $levels;
    }
}
