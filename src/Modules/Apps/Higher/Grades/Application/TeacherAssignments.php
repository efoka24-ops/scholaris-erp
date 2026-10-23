<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Affectations d'un enseignant à un EC (RG-NOT-3) lues par les contrats du socle : compte → personne (Identity UserPersonLink) →
 * fiche du personnel (Staff StaffDirectory) → affectations actives de l'EC (Staff AssignmentWriter::forElement, `element_ref` =
 * identifiant de l'EC de la maquette, `group_ref` = groupe IPES). Aucune affectation = aucun accès.
 */
final class TeacherAssignments
{
    /** @var array<string, ?string> */
    private array $staffIds = [];

    public function __construct(
        private readonly UserPersonLink $links,
        private readonly StaffDirectory $staff,
        private readonly AssignmentWriter $assignments,
    ) {
    }

    public function personId(string $tenantId, string $userId): ?string
    {
        return $this->links->personIdForUser($tenantId, $userId);
    }

    /**
     * Groupes de l'EC confiés à l'enseignant : null = aucune affectation ; liste contenant null = tous les groupes de l'EC.
     *
     * @return list<?string>|null
     */
    public function groupsFor(string $tenantId, string $establishmentId, string $userId, string $elementId): ?array
    {
        $staffId = $this->staffId($tenantId, $userId);
        if ($staffId === null) {
            return null;
        }
        $groups = [];
        foreach ($this->assignments->forElement($tenantId, $establishmentId, $elementId) as $assignment) {
            if ($assignment->staffId === $staffId && $assignment->status === 'active') {
                $groups[] = $assignment->groupRef;
            }
        }

        return $groups === [] ? null : array_values(array_unique($groups, SORT_REGULAR));
    }

    /** L'enseignant est-il affecté à l'EC pour ce groupe (groupe null = évaluation commune à tout le niveau) ? */
    public function covers(string $tenantId, string $establishmentId, string $userId, string $elementId, ?string $groupId): bool
    {
        $groups = $this->groupsFor($tenantId, $establishmentId, $userId, $elementId);
        if ($groups === null) {
            return false;
        }

        return $groupId === null || in_array(null, $groups, true) || in_array($groupId, $groups, true);
    }

    private function staffId(string $tenantId, string $userId): ?string
    {
        $key = $tenantId . ':' . $userId;
        if (!array_key_exists($key, $this->staffIds)) {
            $personId = $this->links->personIdForUser($tenantId, $userId);
            $info = $personId === null ? null : $this->staff->find($tenantId, $personId);
            $this->staffIds[$key] = $info !== null && $info->status === 'active' ? ($info->staffId ?? $info->personId) : null;
        }

        return $this->staffIds[$key];
    }
}
