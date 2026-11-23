<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Domain;

/**
 * Valeurs de départ du cahier de textes, À CONFIRMER par chaque établissement (paramètre `secondary.syllabus.reminder_days` du
 * socle) : nombre de jours sans séance consignée avant le rappel NOT-12 (le CDC cite 7 jours à titre d'exemple).
 */
final class SyllabusDefaults
{
    public const REMINDER_DAYS_KEY = 'secondary.syllabus.reminder_days';
    public const REMINDER_DAYS = 7;
    public const CONFIRMATION = 'to_confirm';

    private function __construct()
    {
    }
}
