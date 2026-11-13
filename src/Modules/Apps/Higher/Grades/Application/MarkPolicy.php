<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;

/**
 * Politique d'accès aux notes (sécurité propre du CDC, MarkPolicy) :
 *  - enseignant (`assessment.enter`) : seulement les évaluations des EC et groupes qui lui sont affectés (RG-NOT-3) ; toute
 *    tentative hors affectation répond 403 et est journalisée (`higher.grades.denied`, TST-03) ;
 *  - encadrement (lecture `higher.grades.read`, validation `assessment.validate`, correction, examens, publication) : périmètre
 *    école, département, filière ou groupe (RG-ORG-2) ; hors périmètre 404 en lecture, 403 journalisé en écriture ;
 *  - étudiant : ses seules notes publiées (StudentResultsService).
 */
final class MarkPolicy
{
    public const ENTER = 'assessment.enter';
    public const SUBMIT = 'higher.grades.submit';
    public const VALIDATE = 'assessment.validate';
    public const CORRECTION_APPROVE = 'assessment.correction.approve';
    public const PUBLISH = 'higher.grades.publish';
    public const EXAMS = 'higher.exam.manage';
    public const LIFT = 'higher.anonymity.lift';
    public const QUERY = 'higher.query.create';

    /** Permissions de lecture de l'encadrement (périmètre). */
    public const READ = ['higher.grades.read', self::VALIDATE, self::CORRECTION_APPROVE, self::EXAMS, self::LIFT, self::PUBLISH];

    /** Colonnes de périmètre des tables de notes. */
    public const COLUMNS = ['school' => 'school_id', 'department' => 'department_id', 'program' => 'program_id', 'level' => 'level_id', 'group' => 'group_id'];

    public function __construct(
        private readonly Access $access,
        private readonly PermissionChecker $permissions,
        private readonly TeacherAssignments $teachers,
    ) {
    }

    /**
     * @param array<string, mixed> $row ligne de hi_grade_assessments (ou de table portant les mêmes colonnes)
     * @return array<string, ?string>
     */
    public static function scope(array $row): array
    {
        $scope = [];
        foreach (self::COLUMNS as $key => $column) {
            if (array_key_exists($column, $row)) {
                $scope[$key] = $row[$column] === null ? null : (string) $row[$column];
            }
        }

        return $scope;
    }

    /** @param list<string> $permissions */
    public function perimeter(Principal $principal, string $establishmentId, array $permissions, ?string $school = null): Perimeter
    {
        return $this->access->perimeter($principal, $establishmentId, $permissions, $school);
    }

    public function can(Principal $principal, string $establishmentId, string $permission): bool
    {
        return $this->permissions->can($principal, $permission, $establishmentId);
    }

    /**
     * L'enseignant porte la permission ET l'affectation de l'EC (et du groupe) de l'évaluation.
     *
     * @param array<string, mixed> $row
     */
    public function teaches(Principal $principal, string $establishmentId, array $row, string $permission = self::ENTER): bool
    {
        return $this->can($principal, $establishmentId, $permission)
            && $this->teachers->covers($principal->tenantId, $establishmentId, $principal->userId, (string) $row['element_id'], $row['group_id'] === null ? null : (string) $row['group_id']);
    }

    /**
     * Lecture d'une évaluation : enseignant affecté ou encadrement dans le périmètre ; sinon 404.
     *
     * @param array<string, mixed> $row
     */
    public function assertReadable(Principal $principal, string $establishmentId, array $row): void
    {
        if ($this->teaches($principal, $establishmentId, $row)) {
            return;
        }
        $perimeter = $this->access->perimeter($principal, $establishmentId, self::READ);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound('Évaluation introuvable.');
        }
        $this->access->assertReadable($perimeter, self::scope($row));
    }

    /**
     * Écriture réservée à l'enseignant affecté (saisie, import, soumission, demande de correction). TST-03 : 403 journalisé.
     *
     * @param array<string, mixed> $row
     */
    public function assertTeacher(Principal $principal, string $establishmentId, array $row, string $permission = self::ENTER): void
    {
        if ($this->teaches($principal, $establishmentId, $row, $permission)) {
            return;
        }
        $this->access->audit($principal, 'higher.grades.denied', 'higher_grade_assessment', (string) $row['id'], null, [
            'permission' => $permission, 'element_id' => $row['element_id'], 'group_id' => $row['group_id'],
            'reason' => 'EC ou groupe non affecté à cet enseignant (RG-NOT-3).',
        ]);

        throw HttpException::forbidden('Cet élément constitutif (ou ce groupe) ne vous est pas affecté : saisie refusée.');
    }

    /**
     * Écriture de l'encadrement : permission ET périmètre (403 journalisé hors périmètre).
     *
     * @param array<string, mixed> $row
     */
    public function assertScoped(Principal $principal, string $establishmentId, array $row, string $permission, string $objectType = 'higher_grade_assessment'): Perimeter
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, [$permission]);
        if ($perimeter->isEmpty()) {
            $this->access->audit($principal, 'higher.grades.denied', $objectType, (string) $row['id'], null, ['permission' => $permission]);

            throw HttpException::forbidden('Permission requise : ' . $permission . '.');
        }
        $this->access->assertWritable($principal, $perimeter, self::scope($row), $objectType, (string) $row['id']);

        return $perimeter;
    }

    /** @param array<string, mixed> $row */
    public function covers(Principal $principal, string $establishmentId, array $row, string $permission): bool
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, [$permission]);

        return !$perimeter->isEmpty() && ($perimeter->wholeEstablishment || $this->coversRow($perimeter, $row));
    }

    /** @param array<string, mixed> $row */
    private function coversRow(Perimeter $perimeter, array $row): bool
    {
        foreach (self::scope($row) as $key => $id) {
            if (in_array($key, ['school', 'department'], true) ? $perimeter->coversOrgUnit($id) : $perimeter->coversStructure($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(Principal $principal, string $action, string $objectType, string $objectId, ?array $before = null, ?array $after = null): void
    {
        $this->access->audit($principal, $action, $objectType, $objectId, $before, $after);
    }
}
