<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Annuaire des noms de l'IPES (GET /higher/directory/names) : nom, prénom et matricule éventuel des seules personnes rattachées à
 * l'établissement, pour que les écrans affichent des noms plutôt que des identifiants.
 *
 * Rattachement : étudiant de l'établissement (registre hi_students du module Inscriptions, qui porte le matricule IPES), enseignant
 * affecté à l'établissement ou membre du personnel du client (contrat Staff), titulaire d'un rôle IPES dans l'établissement
 * (contrat Identity, jurys et directions). Les noms viennent du contrat People, déjà cloisonné par client : une personne d'un autre
 * client n'est jamais rendue. Un identifiant inconnu ou non rattaché est simplement absent de la réponse (aucun oracle).
 */
final class PersonNames
{
    public const PERMISSION = 'higher.access';
    public const MAX = 100;
    private const NOT_STAFF = ['apprenant', 'parent'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly StudentDirectory $students,
        private readonly StaffDirectory $staff,
        private readonly RoleDirectory $roles,
        private readonly PersonDirectory $people,
    ) {
    }

    /**
     * @param list<string> $personIds
     * @return list<array{person_id: string, first_name: string, last_name: string, matricule: ?string}>
     */
    public function names(Principal $principal, string $establishmentId, array $personIds): array
    {
        $this->authorize($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $ids = array_values(array_unique($personIds));
        if ($ids === []) {
            return [];
        }

        $matricules = [];
        $attached = [];
        foreach ($ids as $id) {
            $student = $this->students->student($tenantId, $establishmentId, $id);
            if ($student !== null) {
                $attached[$id] = true;
                $matricules[$id] = $student->matricule;
            }
        }
        $teachers = array_flip($this->staff->teacherIdsIn($tenantId, $establishmentId));
        $holders = array_flip($this->roles->personIdsWithRole($tenantId, $establishmentId, self::roleCodes()));
        foreach ($ids as $id) {
            if (!isset($attached[$id]) && (isset($teachers[$id]) || isset($holders[$id]) || $this->staff->find($tenantId, $id) !== null)) {
                $attached[$id] = true;
            }
        }

        $out = [];
        foreach ($this->people->findMany($tenantId, array_keys($attached)) as $person) {
            if ($person->tenantId !== $tenantId || !isset($attached[$person->id])) {
                continue;
            }
            $out[] = ['person_id' => $person->id, 'first_name' => $person->firstName, 'last_name' => $person->lastName, 'matricule' => $matricules[$person->id] ?? null];
        }

        return $out;
    }

    /** Contrôle de droit, fait avant toute validation de la requête (403 sans révéler la forme attendue). */
    public function authorize(Principal $principal, string $establishmentId): void
    {
        if (!$this->permissions->can($principal, self::PERMISSION, $establishmentId)) {
            throw HttpException::forbidden('Permission requise : ' . self::PERMISSION . '.');
        }
    }

    /**
     * Rôles du personnel : ni l'apprenant, ni le garant (rattachés par l'inscription), ni les rôles externes (tutelle, auditeur).
     *
     * @return list<string>
     */
    private static function roleCodes(): array
    {
        $codes = [];
        foreach (RoleMatrix::ROLES as $role) {
            if (!$role['external'] && !in_array($role['code'], self::NOT_STAFF, true)) {
                $codes[] = $role['code'];
            }
        }

        return $codes;
    }
}
