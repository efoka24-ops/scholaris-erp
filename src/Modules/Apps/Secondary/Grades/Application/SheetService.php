<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientLine;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Grille de l'enseignant (T869, US6) : contexte d'une classe pour une séquence (élèves, matières de la classe avec leur coefficient
 * en vigueur, lu du contrat Setup CoefficientBook, matières que la personne peut saisir, paramètres de saisie). Les épreuves et les
 * notes elles-mêmes sont lues et écrites par les routes du socle Assessment (D4 : aucun contrat d'écriture des notes n'existe) après
 * le contrôle d'affectation journalisé de ce service. Relevé de séquence par classe (DOC-08) calculé par le moteur du socle.
 */
final class SheetService
{
    public const READ = ['secondary.grades.read', 'secondary.grades.enter', 'secondary.grades.validate', 'secondary.grades.lock'];

    public function __construct(
        private readonly Access $access,
        private readonly ClassProfiles $classes,
        private readonly CoefficientBook $coefficients,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly ClassResults $results,
        private readonly EntryPolicy $policy,
        private readonly GradesSettings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function sheet(Principal $principal, string $establishmentId, string $classId, string $periodId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $tenantId = $principal->tenantId;
        $class = $this->classOf($tenantId, $establishmentId, $classId);
        $subjects = [];
        foreach ($this->lines($tenantId, $establishmentId, $class) as $line) {
            $decision = $this->policy->evaluate($principal, $establishmentId, $classId, $line->subjectId, self::names($line));
            $subjects[] = self::subject($line) + ['can_enter' => $decision['allowed']];
        }

        return [
            'class' => self::classResource($class),
            'period_id' => $periodId,
            'learners' => $this->learners($tenantId, $classId),
            'subjects' => $subjects,
            'settings' => $this->settings->effective($tenantId, $establishmentId),
        ];
    }

    /**
     * Contrôle préalable à toute saisie, import ou synchronisation hors ligne d'une matière d'une classe (TST-09) : 403 journalisé
     * si la classe ou la matière n'est pas affectée.
     *
     * @return array<string, mixed>
     */
    public function entryCheck(Principal $principal, string $establishmentId, string $classId, string $subjectId): array
    {
        $tenantId = $principal->tenantId;
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        $line = null;
        if ($class !== null) {
            foreach ($this->lines($tenantId, $establishmentId, $class) as $candidate) {
                if ($candidate->subjectId === $subjectId || $candidate->localSubjectId === $subjectId) {
                    $line = $candidate;
                }
            }
        }
        $decision = $this->policy->assertCanEnter($principal, $establishmentId, $classId, $subjectId, $line === null ? [] : self::names($line));
        if ($class === null) {
            throw HttpException::notFound('Classe inconnue du secondaire.');
        }
        if ($line === null) {
            throw HttpException::validation(['subject_id' => ['Cette matière n\'est pas enseignée dans cette classe (coefficients de l\'année).']]);
        }

        return [
            'allowed' => true, 'role' => $decision['role'], 'class_id' => $classId, 'subject' => self::subject($line),
            'offline_entry' => $this->settings->offlineEnabled($tenantId, $establishmentId),
        ];
    }

    /**
     * Relevé des notes de séquence d'une classe (DOC-08) : par élève, moyenne de chaque matière sur la période (épreuves pondérées
     * par le moteur du socle), lettre [EN] si l'établissement a une table, statistiques de la classe par matière.
     *
     * @return array<string, mixed>
     */
    public function statement(Principal $principal, string $establishmentId, string $classId, string $periodId, ?string $subjectId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $tenantId = $principal->tenantId;
        $class = $this->classOf($tenantId, $establishmentId, $classId);
        $letters = $this->settings->letters($tenantId, $establishmentId);
        $names = [];
        foreach ($this->learners($tenantId, $classId) as $learner) {
            $names[$learner['person_id']] = $learner['name'];
        }
        $rows = [];
        $stats = [];
        foreach ($this->results->compute($tenantId, $establishmentId, $classId, $periodId) as $result) {
            $marks = [];
            foreach ($result['subjects'] as $subject) {
                if ($subjectId !== null && $subject['subject_id'] !== $subjectId) {
                    continue;
                }
                $marks[] = $subject + ['letter' => $letters->letterFor($subject['average'])];
                if ($subject['average'] !== null) {
                    $stats[$subject['subject_id']][] = $subject['average'];
                }
            }
            $rows[] = ['person_id' => $result['person_id'], 'name' => $names[$result['person_id']] ?? $result['person_id'], 'average' => $result['average'], 'rank' => $result['rank'], 'subjects' => $marks];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        $summary = [];
        foreach ($stats as $id => $values) {
            $summary[] = ['subject_id' => (string) $id, 'count' => count($values), 'class_avg' => round(array_sum($values) / count($values), 2), 'min' => min($values), 'max' => max($values)];
        }

        return ['class' => self::classResource($class), 'period_id' => $periodId, 'letters_enabled' => $letters->enabled(), 'rows' => $rows, 'subjects' => $summary];
    }

    private function classOf(string $tenantId, string $establishmentId, string $classId): ClassProfileInfo
    {
        return $this->classes->find($tenantId, $establishmentId, $classId) ?? throw HttpException::notFound('Classe inconnue du secondaire.');
    }

    /** @return list<CoefficientLine> */
    private function lines(string $tenantId, string $establishmentId, ClassProfileInfo $class): array
    {
        return $this->coefficients->forLevel($tenantId, $establishmentId, $class->academicYearId, $class->levelCode, $class->streamId);
    }

    /** @return list<array{person_id: string, name: string}> */
    private function learners(string $tenantId, string $classId): array
    {
        $out = [];
        foreach ($this->people->findMany($tenantId, $this->enrollments->personIdsInStructure($tenantId, $classId)) as $person) {
            $out[] = ['person_id' => $person->id, 'name' => trim($person->lastName . ' ' . $person->firstName)];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<string> */
    private static function names(CoefficientLine $line): array
    {
        return [$line->code, $line->label];
    }

    /** @return array<string, mixed> */
    private static function subject(CoefficientLine $line): array
    {
        return [
            'subject_id' => $line->subjectId, 'local_subject_id' => $line->localSubjectId, 'code' => $line->code, 'label' => $line->label,
            'coefficient' => $line->coefficient, 'role' => $line->role, 'counts_in_average' => $line->countsInAverage,
        ];
    }

    /** @return array<string, mixed> */
    private static function classResource(ClassProfileInfo $class): array
    {
        return [
            'id' => $class->structureId, 'label' => $class->label, 'academic_year_id' => $class->academicYearId, 'level_code' => $class->levelCode,
            'stream_id' => $class->streamId, 'section_code' => $class->sectionCode,
        ];
    }
}
