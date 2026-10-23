<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

/** Formatage des nombres pour les messages : « 20 » et non « 20.00 », virgule décimale française. */
final class Num
{
    public static function format(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return str_replace('.', ',', $text === '' ? '0' : $text);
    }
}
