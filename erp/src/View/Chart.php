<?php

declare(strict_types=1);

namespace Scholaris\View;

/**
 * Graphiques traces en SVG, dans la page.
 *
 * Aucune bibliotheque : le rendu ne depend d'aucun telechargement, d'aucune
 * execution de script, et survit a une politique de securite qui interdit les
 * ressources tierces. Sur une connexion camerounaise, une bibliotheque de
 * graphiques pese plus lourd que toute l'application.
 *
 * Deux regles de lecture gouvernent ces traces.
 *
 * Un jeu vide ne produit pas un graphique plat a zero — qui se lit comme une
 * chute — mais un cadre disant qu'il n'y a rien a montrer.
 *
 * Un axe des ordonnees part de zero. Le tronquer exagere visuellement des
 * variations minimes, et c'est la maniere la plus courante de mentir avec un
 * graphique honnete.
 */
final class Chart
{
    /**
     * Courbe d'evolution, avec aire sous la ligne.
     *
     * @param  list<array{label: string, value: float|int}>  $points
     * @param  list<array{label: string, value: float|int}>  $secondary  serie de comparaison, tracee en pointilles
     */
    public static function line(array $points, array $secondary = [], int $width = 720, int $height = 280): string
    {
        $padLeft = 52;
        $padRight = 16;
        $padTop = 16;
        $padBottom = 34;

        if (count($points) < 2) {
            return self::empty($width, $height, 'Pas encore assez de donnees pour tracer une evolution.');
        }

        $plotWidth = $width - $padLeft - $padRight;
        $plotHeight = $height - $padTop - $padBottom;

        $values = array_map(static fn (array $p): float => (float) $p['value'], $points);

        foreach ($secondary as $p) {
            $values[] = (float) $p['value'];
        }

        $max = max($values);
        // L'axe part de zero, et une serie entierement nulle garde une echelle
        // exploitable plutot que de diviser par zero.
        $max = $max > 0 ? $max : 1.0;
        // Une marge au sommet evite que la courbe ne touche le bord.
        $max *= 1.08;

        $x = static function (int $index) use ($points, $padLeft, $plotWidth): float {
            $steps = max(1, count($points) - 1);

            return round($padLeft + $index / $steps * $plotWidth, 1);
        };

        $y = static function (float $value) use ($max, $padTop, $plotHeight): float {
            return round($padTop + (1 - $value / $max) * $plotHeight, 1);
        };

        $svg = '<svg class="chart" viewBox="0 0 '.$width.' '.$height.'" role="img"'
            .' aria-label="Courbe d evolution">';

        $svg .= '<defs><linearGradient id="chartFill" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0%" stop-color="#5b21f5" stop-opacity="0.22"/>'
            .'<stop offset="100%" stop-color="#5b21f5" stop-opacity="0"/>'
            .'</linearGradient></defs>';

        // Lignes de reperage horizontales et graduations.
        for ($i = 0; $i <= 3; $i++) {
            $value = $max * $i / 3;
            $lineY = $y($value);

            $svg .= '<line class="chart__grid" x1="'.$padLeft.'" y1="'.$lineY
                .'" x2="'.($width - $padRight).'" y2="'.$lineY.'"/>';
            $svg .= '<text class="chart__label" x="'.($padLeft - 10).'" y="'.($lineY + 4)
                .'" text-anchor="end">'.self::round($value).'</text>';
        }

        $svg .= self::path($points, $x, $y, true);

        if ($secondary !== []) {
            $svg .= self::path($secondary, $x, $y, false, 'chart__line chart__line--secondary');
        }

        // Graduations horizontales : au-dela de huit etiquettes elles se
        // chevauchent, on n'en garde donc qu'une sur n.
        $every = (int) max(1, ceil(count($points) / 8));

        foreach ($points as $index => $point) {
            if ($index % $every !== 0 && $index !== count($points) - 1) {
                continue;
            }

            $svg .= '<text class="chart__label" x="'.$x($index).'" y="'.($height - 12)
                .'" text-anchor="middle">'.htmlspecialchars((string) $point['label'], ENT_QUOTES, 'UTF-8').'</text>';
        }

        return $svg.'</svg>';
    }

    /**
     * Anneau de repartition.
     *
     * @param  list<array{label: string, value: int|float, color: string}>  $slices
     */
    public static function donut(array $slices, int $size = 240): string
    {
        $total = 0.0;

        foreach ($slices as $slice) {
            $total += (float) $slice['value'];
        }

        if ($total <= 0.0) {
            return self::empty($size, $size, 'Aucune donnee a repartir.');
        }

        $radius = $size * 0.36;
        $thickness = $size * 0.16;
        $center = $size / 2;
        $circumference = 2 * M_PI * $radius;

        $svg = '<svg class="chart" viewBox="0 0 '.$size.' '.$size.'" role="img"'
            .' aria-label="Repartition en anneau">';

        // Le trace commence en haut : un anneau qui demarre a droite se lit
        // moins naturellement.
        $svg .= '<g transform="rotate(-90 '.$center.' '.$center.')">';

        $offset = 0.0;

        foreach ($slices as $slice) {
            $value = (float) $slice['value'];

            if ($value <= 0.0) {
                continue;
            }

            $length = $value / $total * $circumference;

            $svg .= '<circle cx="'.$center.'" cy="'.$center.'" r="'.round($radius, 2).'"'
                .' fill="none" stroke="'.htmlspecialchars($slice['color'], ENT_QUOTES, 'UTF-8').'"'
                .' stroke-width="'.round($thickness, 2).'"'
                .' stroke-dasharray="'.round($length, 2).' '.round($circumference - $length, 2).'"'
                .' stroke-dashoffset="'.round(-$offset, 2).'"/>';

            $offset += $length;
        }

        return $svg.'</g></svg>';
    }

    /**
     * Legende d'un anneau, avec les effectifs.
     *
     * @param  list<array{label: string, value: int|float, color: string}>  $slices
     */
    public static function legend(array $slices): string
    {
        $html = '<ul class="legend">';

        foreach ($slices as $slice) {
            $html .= '<li>'
                .'<i style="background:'.htmlspecialchars($slice['color'], ENT_QUOTES, 'UTF-8').'"></i>'
                .'<span>'.htmlspecialchars((string) $slice['label'], ENT_QUOTES, 'UTF-8').'</span>'
                .'<strong>'.number_format((float) $slice['value'], 0, ',', ' ').'</strong>'
                .'</li>';
        }

        return $html.'</ul>';
    }

    /**
     * @param  list<array{label: string, value: float|int}>  $points
     */
    private static function path(
        array $points,
        callable $x,
        callable $y,
        bool $withArea,
        string $class = 'chart__line'
    ): string {
        $commands = [];

        foreach ($points as $index => $point) {
            $commands[] = ($index === 0 ? 'M' : 'L').$x($index).' '.$y((float) $point['value']);
        }

        $line = implode(' ', $commands);
        $svg = '';

        if ($withArea) {
            $last = count($points) - 1;
            $baseline = $y(0.0);

            $svg .= '<path class="chart__area" d="'.$line
                .' L'.$x($last).' '.$baseline.' L'.$x(0).' '.$baseline.' Z"/>';
        }

        return $svg.'<path class="'.$class.'" d="'.$line.'"/>';
    }

    private static function empty(int $width, int $height, string $message): string
    {
        return '<svg class="chart" viewBox="0 0 '.$width.' '.$height.'" role="img"'
            .' aria-label="'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'">'
            .'<text class="chart__empty" x="'.($width / 2).'" y="'.($height / 2).'">'
            .htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            .'</text></svg>';
    }

    /** Graduation lisible : les milliers abreges, le reste arrondi. */
    private static function round(float $value): string
    {
        if ($value >= 1000000) {
            return number_format($value / 1000000, 1, ',', ' ').'M';
        }

        if ($value >= 1000) {
            return number_format($value / 1000, 1, ',', ' ').'k';
        }

        return number_format($value, 0, ',', ' ');
    }
}
