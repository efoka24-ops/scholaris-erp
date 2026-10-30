<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

use DateTimeImmutable;

/** Plage horaire d'envoi (7 h à 21 h par défaut), évaluée dans le fuseau de l'instant fourni. */
final class SendingWindow
{
    public function __construct(
        private readonly int $startHour = 7,
        private readonly int $endHour = 21,
    ) {
    }

    public function isOpen(DateTimeImmutable $moment): bool
    {
        $minutes = (int) $moment->format('G') * 60 + (int) $moment->format('i');

        return $minutes >= $this->startHour * 60 && $minutes < $this->endHour * 60;
    }

    /** Prochaine ouverture : aujourd'hui si on est avant l'heure de début, sinon demain. */
    public function nextOpening(DateTimeImmutable $moment): DateTimeImmutable
    {
        $today = $moment->setTime($this->startHour, 0);

        return $moment < $today ? $today : $today->modify('+1 day');
    }
}
