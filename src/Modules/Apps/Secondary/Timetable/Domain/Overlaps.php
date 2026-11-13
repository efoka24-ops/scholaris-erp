<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/**
 * Conflits d'emploi du temps (EX-EDT-02) : même enseignant, même salle (atelier, laboratoire compris) ou même classe au même
 * moment. Une classe entière heurte ses demi-groupes ; deux demi-groupes différents de la même classe ne se heurtent pas
 * (dédoublement, EX-EDT-03).
 */
final class Overlaps
{
    public const TEACHER = 'teacher';
    public const ROOM = 'room';
    public const SCHOOL_CLASS = 'class';

    private function __construct()
    {
    }

    /**
     * @param iterable<Occupation> $others
     * @return list<array{kind: string, ref: string, message: string}>
     */
    public static function conflicts(Occupation $candidate, iterable $others): array
    {
        $found = [];
        foreach ($others as $other) {
            if ($other->ref === $candidate->ref || !$candidate->overlaps($other)) {
                continue;
            }
            $when = sprintf('%s-%s', $other->startTime, $other->endTime);
            if ($candidate->teacherId !== null && $candidate->teacherId === $other->teacherId) {
                $found[] = ['kind' => self::TEACHER, 'ref' => $other->ref, 'message' => sprintf('L\'enseignant a déjà cours à ce moment (%s %s).', $when, $other->label)];
            }
            if ($candidate->roomId !== null && $candidate->roomId === $other->roomId) {
                $found[] = ['kind' => self::ROOM, 'ref' => $other->ref, 'message' => sprintf('La salle ou l\'atelier est déjà occupé à ce moment (%s %s).', $when, $other->label)];
            }
            if ($candidate->sharesClass($other)) {
                $found[] = ['kind' => self::SCHOOL_CLASS, 'ref' => $other->ref, 'message' => sprintf('La classe a déjà cours à ce moment (%s %s).', $when, $other->label)];
            }
        }

        return $found;
    }

    /**
     * Tous les conflits d'un ensemble d'occupations (contrôle d'un emploi du temps posé).
     *
     * @param list<Occupation> $all
     * @return list<array{kind: string, refs: list<string>, message: string}>
     */
    public static function detect(array $all): array
    {
        $found = [];
        $count = count($all);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                foreach (self::conflicts($all[$i], [$all[$j]]) as $conflict) {
                    $found[] = ['kind' => $conflict['kind'], 'refs' => [$all[$i]->ref, $all[$j]->ref], 'message' => $conflict['message']];
                }
            }
        }

        return $found;
    }
}
