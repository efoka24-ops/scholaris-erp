<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Domain;

/**
 * Valeurs de départ de l'emploi du temps, À CONFIRMER par chaque établissement (paramètres `secondary.timetable.*` du socle) :
 * aucune n'est officielle. Le nombre d'heures d'une même matière dans une journée borne la répartition du générateur.
 */
final class TimetableDefaults
{
    public const MAX_SAME_SUBJECT_PER_DAY_KEY = 'secondary.timetable.max_same_subject_per_day';
    public const MAX_SAME_SUBJECT_PER_DAY = 2;
    public const CONFIRMATION = 'to_confirm';

    private function __construct()
    {
    }
}
