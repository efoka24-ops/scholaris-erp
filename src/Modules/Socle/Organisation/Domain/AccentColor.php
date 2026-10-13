<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

use InvalidArgumentException;

/**
 * Couleur d'accent d'un établissement. Le texte posé dessus est toujours noir : la couleur est refusée
 * si le contraste (formule WCAG 2.x) avec le noir est inférieur à 4,5:1.
 */
final class AccentColor
{
    public const DEFAULT = '#F26B1D';
    public const MIN_CONTRAST = 4.5;

    private function __construct(private readonly string $hex)
    {
    }

    /** @throws InvalidArgumentException message en français, destiné à l'utilisateur */
    public static function parse(string $value): self
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException('La couleur d\'accent doit être au format #RRGGBB, par exemple #F26B1D.');
        }
        $color = new self(strtoupper($value));
        $contrast = $color->contrastWithBlack();
        if ($contrast < self::MIN_CONTRAST) {
            throw new InvalidArgumentException(sprintf(
                'Le contraste de cette couleur avec le texte noir est de %s:1 ; il doit être d\'au moins 4,5:1. Choisissez une couleur plus claire.',
                str_replace('.', ',', number_format($contrast, 2, '.', '')),
            ));
        }

        return $color;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public function hex(): string
    {
        return $this->hex;
    }

    /** Rapport de contraste avec du texte noir : (L + 0,05) / 0,05. */
    public function contrastWithBlack(): float
    {
        return ($this->luminance() + 0.05) / 0.05;
    }

    private function luminance(): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($this->hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
