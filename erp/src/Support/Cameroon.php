<?php

declare(strict_types=1);

namespace Scholaris\Support;

/**
 * Geographie du Cameroun : les dix regions et le trace du pays.
 *
 * La carte du tableau de bord est schematique et assumee comme telle. Son
 * objet n'est pas la precision cartographique mais la lecture d'un coup d'oeil :
 * ou se trouve le parc, ou sont les demandes en attente. Un trace simplifie
 * suffit a cela et tient dans la page, sans bibliotheque ni appel exterieur —
 * ce qui compte sur une connexion camerounaise et derriere une politique de
 * securite qui interdit les ressources tierces.
 *
 * Contour et reperes sont exprimes en longitude / latitude reelles et projetes
 * par la meme fonction : un etablissement se pose donc au bon endroit du
 * dessin, et non a une position ajustee a la main.
 */
final class Cameroon
{
    /** Cadre geographique du pays, avec une marge. */
    private const MIN_LON = 8.3;

    private const MAX_LON = 16.4;

    private const MIN_LAT = 1.8;

    private const MAX_LAT = 13.3;

    /** Dimensions du dessin, en unites SVG. */
    public const WIDTH = 600;

    public const HEIGHT = 820;

    private const PADDING = 24;

    /**
     * Frontiere nationale, dans le sens des aiguilles d'une montre depuis la
     * pointe du lac Tchad. Trace simplifie : une quarantaine de points suffit
     * a rendre la silhouette reconnaissable.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const OUTLINE = [
        // Frontiere tchadienne, du lac Tchad vers le sud.
        [14.20, 13.08], [14.60, 12.80], [14.90, 12.10], [15.05, 11.60],
        [15.10, 11.05], [15.30, 10.55], [15.50, 10.00], [15.15, 9.65],
        [15.25, 9.30], [15.05, 8.95], [15.10, 8.60], [15.50, 8.30],
        [15.60, 7.75], [15.20, 7.30], [14.75, 6.80], [14.55, 6.25],
        [14.60, 5.85],
        // Frontiere centrafricaine, vers le sud-est.
        [15.05, 5.05], [15.60, 4.25], [16.05, 3.55], [16.20, 2.90],
        [15.80, 2.05],
        // Frontiere sud : Congo, Gabon, Guinee equatoriale.
        [14.45, 2.20], [13.20, 2.25], [11.35, 2.17], [9.85, 2.15],
        // Facade maritime, vers le nord.
        [9.92, 2.40], [9.80, 3.05], [9.55, 3.55], [9.30, 3.95],
        [8.80, 4.10], [8.55, 4.55], [8.85, 5.15],
        // Frontiere nigeriane, remontant vers le lac Tchad.
        [8.90, 5.60], [9.10, 6.00], [9.35, 6.30], [9.65, 6.45],
        [10.00, 6.60], [10.25, 6.90], [10.60, 7.05], [10.90, 6.95],
        [11.15, 6.75], [11.50, 6.90], [11.75, 7.35], [12.00, 7.95],
        [12.25, 8.35], [12.45, 8.65], [12.80, 8.80], [13.25, 8.55],
        [13.55, 9.05], [13.90, 9.60], [13.75, 10.05], [13.55, 10.60],
        [13.45, 11.10], [13.70, 11.55], [14.05, 11.95], [14.20, 12.40],
    ];

    /**
     * Les dix regions, situees sur leur chef-lieu.
     *
     * @var array<string, array{name: string, capital: string, lon: float, lat: float}>
     */
    private const REGIONS = [
        'ADAMAOUA' => ['name' => 'Adamaoua', 'capital' => 'Ngaoundere', 'lon' => 13.58, 'lat' => 7.33],
        'CENTRE' => ['name' => 'Centre', 'capital' => 'Yaounde', 'lon' => 11.52, 'lat' => 3.87],
        'EST' => ['name' => 'Est', 'capital' => 'Bertoua', 'lon' => 13.68, 'lat' => 4.58],
        'EXTREME_NORD' => ['name' => 'Extreme-Nord', 'capital' => 'Maroua', 'lon' => 14.32, 'lat' => 10.60],
        'LITTORAL' => ['name' => 'Littoral', 'capital' => 'Douala', 'lon' => 9.71, 'lat' => 4.05],
        'NORD' => ['name' => 'Nord', 'capital' => 'Garoua', 'lon' => 13.40, 'lat' => 9.30],
        'NORD_OUEST' => ['name' => 'Nord-Ouest', 'capital' => 'Bamenda', 'lon' => 10.15, 'lat' => 5.96],
        'OUEST' => ['name' => 'Ouest', 'capital' => 'Bafoussam', 'lon' => 10.42, 'lat' => 5.48],
        'SUD' => ['name' => 'Sud', 'capital' => 'Ebolowa', 'lon' => 11.15, 'lat' => 2.90],
        'SUD_OUEST' => ['name' => 'Sud-Ouest', 'capital' => 'Buea', 'lon' => 9.24, 'lat' => 4.15],
    ];

    /**
     * Les cinquante-huit departements, par region.
     *
     * Referentiel plutot que champ libre : « Mfoundi », « MFOUNDI » et
     * « Mfoundi (Yaounde) » saisis a la main dans trois etablissements
     * donneraient trois departements distincts, et aucun rapport
     * departemental ne tomberait juste.
     *
     * @var array<string, list<string>>
     */
    private const DEPARTMENTS = [
        'ADAMAOUA' => ['Djerem', 'Faro-et-Deo', 'Mayo-Banyo', 'Mbere', 'Vina'],
        'CENTRE' => [
            'Haute-Sanaga', 'Lekie', 'Mbam-et-Inoubou', 'Mbam-et-Kim',
            'Mefou-et-Afamba', 'Mefou-et-Akono', 'Mfoundi', 'Nyong-et-Kelle',
            'Nyong-et-Mfoumou', 'Nyong-et-So o',
        ],
        'EST' => ['Boumba-et-Ngoko', 'Haut-Nyong', 'Kadey', 'Lom-et-Djerem'],
        'EXTREME_NORD' => [
            'Diamare', 'Logone-et-Chari', 'Mayo-Danay', 'Mayo-Kani',
            'Mayo-Sava', 'Mayo-Tsanaga',
        ],
        'LITTORAL' => ['Moungo', 'Nkam', 'Sanaga-Maritime', 'Wouri'],
        'NORD' => ['Benoue', 'Faro', 'Mayo-Louti', 'Mayo-Rey'],
        'NORD_OUEST' => ['Boyo', 'Bui', 'Donga-Mantung', 'Menchum', 'Mezam', 'Momo', 'Ngo-Ketunjia'],
        'OUEST' => [
            'Bamboutos', 'Haut-Nkam', 'Hauts-Plateaux', 'Koung-Khi',
            'Menoua', 'Mifi', 'Nde', 'Noun',
        ],
        'SUD' => ['Dja-et-Lobo', 'Mvila', 'Ocean', 'Vallee-du-Ntem'],
        'SUD_OUEST' => ['Fako', 'Koupe-Manengouba', 'Lebialem', 'Manyu', 'Meme', 'Ndian'],
    ];

    /**
     * Ministeres de tutelle.
     *
     * Un etablissement en releve d'un seul, et c'est ce rattachement qui
     * determine a quel rapport ministeriel il contribue.
     *
     * @var array<string, string>
     */
    private const MINISTRIES = [
        'MINEDUB' => 'MINEDUB — Education de base',
        'MINESEC' => 'MINESEC — Enseignements secondaires',
        'MINESUP' => 'MINESUP — Enseignement superieur',
        'MINEFOP' => 'MINEFOP — Emploi et formation professionnelle',
    ];

    /**
     * @return array<string, array{name: string, capital: string, lon: float, lat: float}>
     */
    public static function regions(): array
    {
        return self::REGIONS;
    }

    /**
     * Departements d'une region, ou tous si aucune n'est precisee.
     *
     * @return list<string>
     */
    public static function departments(?string $region = null): array
    {
        if ($region !== null) {
            return self::DEPARTMENTS[$region] ?? [];
        }

        $all = [];

        foreach (self::DEPARTMENTS as $departments) {
            foreach ($departments as $department) {
                $all[] = $department;
            }
        }

        sort($all);

        return $all;
    }

    /** @return array<string, list<string>> */
    public static function departmentsByRegion(): array
    {
        return self::DEPARTMENTS;
    }

    /**
     * La region a laquelle appartient un departement.
     *
     * Permet de deduire la region plutot que de la redemander, et d'eviter
     * qu'un etablissement soit declare dans le Mfoundi et dans le Nord.
     */
    public static function regionOfDepartment(string $department): ?string
    {
        foreach (self::DEPARTMENTS as $region => $departments) {
            foreach ($departments as $candidate) {
                if (mb_strtolower($candidate) === mb_strtolower($department)) {
                    return $region;
                }
            }
        }

        return null;
    }

    public static function isDepartment(string $department): bool
    {
        return self::regionOfDepartment($department) !== null;
    }

    /** @return array<string, string> */
    public static function ministries(): array
    {
        return self::MINISTRIES;
    }

    public static function isMinistry(string $code): bool
    {
        return isset(self::MINISTRIES[$code]);
    }

    public static function ministryName(?string $code): string
    {
        if ($code === null || ! isset(self::MINISTRIES[$code])) {
            return 'Tutelle non renseignee';
        }

        return self::MINISTRIES[$code];
    }

    /**
     * Ministere qui a naturellement la tutelle d'un type d'etablissement.
     *
     * Sert de proposition a la creation : le rattachement reste modifiable,
     * un etablissement pouvant relever d'une tutelle particuliere.
     */
    public static function ministryForType(string $type): ?string
    {
        return match ($type) {
            'PRIMAIRE' => 'MINEDUB',
            'COLLEGE', 'LYCEE_GENERAL', 'LYCEE_TECHNIQUE' => 'MINESEC',
            'SUPERIEUR' => 'MINESUP',
            'CENTRE_FORMATION' => 'MINEFOP',
            default => null,
        };
    }

    /** @return array<string, string> code vers libelle, pour un menu deroulant */
    public static function regionChoices(): array
    {
        $choices = [];

        foreach (self::REGIONS as $code => $region) {
            $choices[$code] = $region['name'];
        }

        return $choices;
    }

    public static function regionName(?string $code): string
    {
        if ($code === null || ! isset(self::REGIONS[$code])) {
            return 'Region non renseignee';
        }

        return self::REGIONS[$code]['name'];
    }

    public static function isRegion(string $code): bool
    {
        return isset(self::REGIONS[$code]);
    }

    /**
     * Projette une position geographique dans le repere du dessin.
     *
     * @return array{x: float, y: float}
     */
    public static function project(float $lon, float $lat): array
    {
        $usableWidth = self::WIDTH - 2 * self::PADDING;
        $usableHeight = self::HEIGHT - 2 * self::PADDING;

        $x = self::PADDING + ($lon - self::MIN_LON) / (self::MAX_LON - self::MIN_LON) * $usableWidth;
        // La latitude croit vers le nord, l'ordonnee SVG vers le bas.
        $y = self::PADDING + (self::MAX_LAT - $lat) / (self::MAX_LAT - self::MIN_LAT) * $usableHeight;

        return ['x' => round($x, 1), 'y' => round($y, 1)];
    }

    /** Trace du pays, pret a poser dans un attribut "d". */
    public static function outlinePath(): string
    {
        $commands = [];

        foreach (self::OUTLINE as $index => [$lon, $lat]) {
            $point = self::project($lon, $lat);
            $commands[] = ($index === 0 ? 'M' : 'L').$point['x'].' '.$point['y'];
        }

        return implode(' ', $commands).' Z';
    }

    /**
     * Position d'une region dans le dessin.
     *
     * @return array{x: float, y: float}|null null si la region est inconnue
     */
    public static function regionPoint(?string $code): ?array
    {
        if ($code === null || ! isset(self::REGIONS[$code])) {
            return null;
        }

        return self::project(self::REGIONS[$code]['lon'], self::REGIONS[$code]['lat']);
    }
}
