<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/**
 * Générateur assisté d'emploi du temps (EX-EDT-01 à 03, T860), déterministe : place chaque besoin plage par plage sans jamais
 * créer de conflit d'enseignant, de salle ou de classe (créneaux déjà posés compris), dans les disponibilités de l'enseignant
 * (vacataire : seulement dans ses plages déclarées « disponible »), en répartissant une matière sur la semaine (au plus
 * `maxPerDay` plages par jour). Les besoins les plus contraints passent d'abord. Ce qui ne peut pas être placé est rendu
 * (« non placé ») : le censeur le complète à la main, rien n'est forcé.
 */
final class TimetableGenerator
{
    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param list<Band> $bands plages de cours de l'établissement
     * @param list<Demand> $demands
     * @param list<Occupation> $fixed créneaux déjà posés et conservés
     * @param array<string, list<array{weekday: int, start_time: string, end_time: string, availability_kind: string}>> $availability par enseignant
     * @return array{placed: list<array{demand: Demand, band: Band}>, unplaced: list<array{demand: Demand, missing: int}>}
     */
    public function generate(array $bands, array $demands, array $fixed, array $availability, int $maxPerDay): array
    {
        usort($bands, static fn (Band $a, Band $b): int => [$a->weekday, $a->startTime] <=> [$b->weekday, $b->startTime]);
        $options = [];
        foreach ($demands as $demand) {
            $options[$demand->assignmentId] = count(array_filter($bands, static fn (Band $band): bool => self::available($band, $availability[$demand->teacherId] ?? [])));
        }
        usort($demands, static fn (Demand $a, Demand $b): int => [$options[$a->assignmentId], -$a->bandsNeeded, $a->classId, $a->assignmentId]
            <=> [$options[$b->assignmentId], -$b->bandsNeeded, $b->classId, $b->assignmentId]);

        $occupied = $fixed;
        $placed = [];
        $unplaced = [];
        $perDay = [];
        $classLoad = [];
        foreach ($demands as $demand) {
            $missing = 0;
            for ($n = 0; $n < $demand->bandsNeeded; ++$n) {
                $best = null;
                $bestScore = null;
                foreach ($bands as $index => $band) {
                    $sameDay = $perDay[$demand->assignmentId][$band->weekday] ?? 0;
                    if ($sameDay >= $maxPerDay || !self::available($band, $availability[$demand->teacherId] ?? [])) {
                        continue;
                    }
                    $candidate = self::occupation($demand, $band);
                    if (Overlaps::conflicts($candidate, $occupied) !== []) {
                        continue;
                    }
                    $score = [$sameDay, $classLoad[$demand->classId][$band->weekday] ?? 0, $index];
                    if ($bestScore === null || $score < $bestScore) {
                        [$best, $bestScore] = [$band, $score];
                    }
                }
                if ($best === null) {
                    ++$missing;
                    continue;
                }
                $occupied[] = self::occupation($demand, $best);
                $placed[] = ['demand' => $demand, 'band' => $best];
                $perDay[$demand->assignmentId][$best->weekday] = ($perDay[$demand->assignmentId][$best->weekday] ?? 0) + 1;
                $classLoad[$demand->classId][$best->weekday] = ($classLoad[$demand->classId][$best->weekday] ?? 0) + 1;
            }
            if ($missing > 0) {
                $unplaced[] = ['demand' => $demand, 'missing' => $missing];
            }
        }

        return ['placed' => $placed, 'unplaced' => $unplaced];
    }

    /**
     * Une plage est permise si elle ne touche aucune indisponibilité et, quand l'enseignant a déclaré des plages « disponible »
     * (vacataire), si elle tient entièrement dans l'une d'elles.
     *
     * @param list<array{weekday: int, start_time: string, end_time: string, availability_kind: string}> $windows
     */
    public static function available(Band $band, array $windows): bool
    {
        $declared = false;
        $inside = false;
        foreach ($windows as $window) {
            if ($window['availability_kind'] === self::AVAILABLE) {
                $declared = true;
                if ($window['weekday'] === $band->weekday && $window['start_time'] <= $band->startTime && $band->endTime <= $window['end_time']) {
                    $inside = true;
                }
                continue;
            }
            if ($window['weekday'] === $band->weekday && $window['start_time'] < $band->endTime && $band->startTime < $window['end_time']) {
                return false;
            }
        }

        return !$declared || $inside;
    }

    private static function occupation(Demand $demand, Band $band): Occupation
    {
        return new Occupation('new:' . $demand->assignmentId . ':' . $band->key(), $band->weekday, $band->startTime, $band->endTime, $demand->teacherId, $demand->classId, $demand->groupCode, $demand->roomId, '', null, $demand->label);
    }
}
