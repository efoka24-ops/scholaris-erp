<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Domain;

/** Note sur 20 au pas de 0,25 (fiche d'évaluation du tuteur, note de soutenance), stockée en centièmes. */
final class Grade
{
    public const MAX = 20.0;
    public const STEP = 0.25;

    private function __construct()
    {
    }

    /** Centièmes de la note, ou null si elle n'est pas une note valide (0 à 20 au pas de 0,25). */
    public static function toHundredths(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $hundredths = (int) round(((float) $value) * 100);
        if ($hundredths < 0 || $hundredths > (int) (self::MAX * 100) || $hundredths % (int) (self::STEP * 100) !== 0) {
            return null;
        }

        return $hundredths;
    }

    public static function fromHundredths(mixed $hundredths): ?float
    {
        return $hundredths === null ? null : round(((int) $hundredths) / 100, 2);
    }

    public static function label(int $hundredths, string $locale = 'fr'): string
    {
        $text = number_format($hundredths / 100, 2, $locale === 'en' ? '.' : ',', '');

        return $text . '/20';
    }
}
