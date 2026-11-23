<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Domain;

/**
 * Conflits d'un emploi du temps posé (EX-EDT-02) : même enseignant, même salle ou même groupe sur des plages qui se chevauchent
 * (même jour, heures et périodes de validité croisées), et capacité de salle dépassée. Un cours mutualisé est un seul créneau à
 * plusieurs groupes : il ne se heurte pas à lui-même. Sert à la vue « conflits » et au contrôle avant la pose.
 */
final class TimetableOverlaps
{
    public const TEACHER = 'teacher';
    public const ROOM = 'room';
    public const GROUP = 'group';
    public const CAPACITY = 'capacity';

    private function __construct()
    {
    }

    public static function minutes(string $hhmm): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
            throw new \InvalidArgumentException('Heure HH:MM attendue : ' . $hhmm);
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Jour ou soir selon l'heure de début (EX-EDT-03). */
    public static function dayPeriod(string $startTime, string $eveningFrom = '17:00'): string
    {
        return self::minutes($startTime) >= self::minutes($eveningFrom) ? 'evening' : 'day';
    }

    /**
     * @param array{weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string} $a
     * @param array{weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string} $b
     */
    public static function overlap(array $a, array $b): bool
    {
        if ($a['weekday'] !== $b['weekday']) {
            return false;
        }
        if (self::minutes($a['start_time']) >= self::minutes($b['end_time']) || self::minutes($b['start_time']) >= self::minutes($a['end_time'])) {
            return false;
        }
        $aTo = $a['valid_to'] ?? '9999-12-31';
        $bTo = $b['valid_to'] ?? '9999-12-31';

        return $a['valid_from'] <= $bTo && $b['valid_from'] <= $aTo;
    }

    /**
     * @param list<array{id: string, weekday: int, start_time: string, end_time: string, valid_from: string, valid_to: ?string,
     *     person_id: string, room_id: ?string, groups: list<string>, headcount: int, room_capacity: ?int}> $entries
     * @return list<array{kind: string, entry_ids: list<string>, message: string}>
     */
    public static function detect(array $entries): array
    {
        $conflicts = [];
        $count = count($entries);
        for ($i = 0; $i < $count; ++$i) {
            $a = $entries[$i];
            if ($a['room_capacity'] !== null && $a['headcount'] > $a['room_capacity']) {
                $conflicts[] = ['kind' => self::CAPACITY, 'entry_ids' => [$a['id']], 'message' => sprintf('La salle (%d places) ne peut pas accueillir %d étudiants.', $a['room_capacity'], $a['headcount'])];
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                $b = $entries[$j];
                if (!self::overlap($a, $b)) {
                    continue;
                }
                $pair = [$a['id'], $b['id']];
                $when = sprintf('jour %d, %s-%s', $a['weekday'], $b['start_time'], $b['end_time']);
                if ($a['person_id'] === $b['person_id']) {
                    $conflicts[] = ['kind' => self::TEACHER, 'entry_ids' => $pair, 'message' => 'Enseignant déjà en cours (' . $when . ').'];
                }
                if ($a['room_id'] !== null && $a['room_id'] === $b['room_id']) {
                    $conflicts[] = ['kind' => self::ROOM, 'entry_ids' => $pair, 'message' => 'Salle déjà occupée (' . $when . ').'];
                }
                if (array_intersect($a['groups'], $b['groups']) !== []) {
                    $conflicts[] = ['kind' => self::GROUP, 'entry_ids' => $pair, 'message' => 'Groupe déjà en cours (' . $when . ').'];
                }
            }
        }

        return $conflicts;
    }
}
