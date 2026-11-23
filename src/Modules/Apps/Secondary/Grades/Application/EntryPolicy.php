<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\TeacherClasses;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/**
 * Politique de saisie par affectation (CDC « Sécurité propre », TST-09, R09 « ses classes et matières ») :
 *  - le censeur (`secondary.grades.validate` ou `.lock` sur la classe) saisit pour toute la classe ;
 *  - l'enseignant (`secondary.grades.enter`) saisit pour une classe qui lui est affectée : rôle donné sur la classe elle-même,
 *    affectation active du personnel (Staff) sur la classe, ou créneau d'emploi du temps en vigueur dans la classe ;
 *  - si ses affectations du personnel sur la classe précisent des matières, la matière saisie doit en faire partie.
 * Tout refus est une réponse 403 JOURNALISÉE (`secondary.grades.denied`) : classe, matière, motif. Le socle contrôle en plus, à chaque
 * note, que les élèves sont bien ceux de l'enseignant (TeachingScope) et que l'épreuve n'est pas verrouillée.
 */
final class EntryPolicy
{
    public const VALIDATORS = ['secondary.grades.validate', 'secondary.grades.lock'];
    public const ENTER = ['secondary.grades.enter'];

    public function __construct(
        private readonly Access $access,
        private readonly ActorDirectory $actors,
        private readonly TeacherWorkload $workload,
        private readonly TeacherClasses $timetable,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param list<string> $subjectNames code et libellés de la matière (comparés sans casse ni accents aux matières des affectations)
     * @return array{allowed: bool, role: string, reason: string|null}
     */
    public function evaluate(Principal $principal, string $establishmentId, string $classId, ?string $subjectId, array $subjectNames = []): array
    {
        if ($this->access->perimeter($principal, $establishmentId, self::VALIDATORS)->coversClass($classId)) {
            return ['allowed' => true, 'role' => 'validator', 'reason' => null];
        }
        $enter = $this->access->perimeter($principal, $establishmentId, self::ENTER);
        if ($enter->isEmpty()) {
            return ['allowed' => false, 'role' => 'none', 'reason' => 'no_permission'];
        }
        if (!$enter->coversClass($classId)) {
            return ['allowed' => false, 'role' => 'teacher', 'reason' => 'class_out_of_scope'];
        }
        $person = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($person === null) {
            return ['allowed' => false, 'role' => 'teacher', 'reason' => 'no_person'];
        }
        $labels = [];
        $assigned = !$enter->wholeEstablishment;
        foreach ($this->workload->assignmentsOf($principal->tenantId, $person) as $assignment) {
            if ($assignment['establishment_id'] !== $establishmentId || $assignment['structure_id'] !== $classId) {
                continue;
            }
            $assigned = true;
            if ($assignment['subject_label'] !== null && trim($assignment['subject_label']) !== '') {
                $labels[] = self::normalise($assignment['subject_label']);
            }
        }
        if (!$assigned && in_array($classId, $this->timetable->structureIdsOf($principal->tenantId, $person, $this->clock->today()), true)) {
            $assigned = true;
        }
        if (!$assigned) {
            return ['allowed' => false, 'role' => 'teacher', 'reason' => 'class_not_assigned'];
        }
        if ($subjectId !== null && $labels !== []) {
            $names = [];
            foreach ($subjectNames as $name) {
                if (trim($name) !== '') {
                    $names[] = self::normalise($name);
                }
            }
            if (array_intersect($labels, $names) === []) {
                return ['allowed' => false, 'role' => 'teacher', 'reason' => 'subject_not_assigned'];
            }
        }

        return ['allowed' => true, 'role' => 'teacher', 'reason' => null];
    }

    /**
     * Refus 403 journalisé si la saisie n'est pas permise.
     *
     * @param list<string> $subjectNames
     * @return array{allowed: bool, role: string, reason: string|null}
     */
    public function assertCanEnter(Principal $principal, string $establishmentId, string $classId, ?string $subjectId, array $subjectNames = []): array
    {
        $decision = $this->evaluate($principal, $establishmentId, $classId, $subjectId, $subjectNames);
        if ($decision['allowed']) {
            return $decision;
        }
        $this->access->audit($principal, 'secondary.grades.denied', 'se_grade_entry', $classId, null, [
            'class_id' => $classId, 'subject_id' => $subjectId, 'reason' => $decision['reason'],
        ]);

        throw HttpException::forbidden(match ($decision['reason']) {
            'no_permission' => 'Permission requise : secondary.grades.enter.',
            'subject_not_assigned' => 'Cette matière ne vous est pas affectée dans cette classe. La tentative est enregistrée.',
            'no_person' => 'Votre compte n\'est rattaché à aucune personne : vous ne pouvez pas saisir de notes.',
            default => 'Cette classe ne vous est pas affectée : vous ne pouvez pas y saisir de notes. La tentative est enregistrée.',
        });
    }

    private static function normalise(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $ascii === false ? $value : $ascii));
    }
}
