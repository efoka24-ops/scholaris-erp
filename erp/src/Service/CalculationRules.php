<?php

declare(strict_types=1);

namespace Scholaris\Service;

/**
 * Regles de calcul des moyennes, propres a chaque etablissement.
 *
 * Elles etaient ecrites en dur dans le moteur : bareme sur vingt, arrondi au
 * centieme, mentions a 16/14/12/10, absence non justifiee comptee zero. Ces
 * choix conviennent a un lycee camerounais et a personne d'autre — une ecole
 * primaire note volontiers sur dix, et un etablissement peut decider qu'une
 * absence ne compte pas plutot que de valoir zero.
 *
 * Deux precautions gouvernent cette classe.
 *
 * Une regle n'existe ici que si le moteur l'applique reellement. Proposer un
 * reglage qui n'agit sur rien est pire que de ne pas l'offrir : l'utilisateur
 * croit avoir configure quelque chose, et cherche l'erreur ailleurs quand le
 * resultat ne change pas.
 *
 * Une valeur invalide retombe sur la valeur par defaut plutot que d'etre
 * appliquee telle quelle. Un bareme a zero ou des seuils de mention en
 * desordre ne doivent pas produire des bulletins faux ; ils doivent produire
 * des bulletins ordinaires.
 */
final class CalculationRules
{
    public const ABSENCE_ZERO = 'ZERO';

    public const ABSENCE_IGNORED = 'IGNORED';

    /** @var array<string, mixed> */
    private array $values;

    /**
     * Reglages par defaut, ceux qui etaient jusqu'ici ecrits en dur.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'scale' => 20.0,
        'pass_mark' => 10.0,
        'rounding' => 2,
        'unjustified_absence' => self::ABSENCE_ZERO,
        'mentions' => [
            ['threshold' => 16.0, 'label' => 'Tres bien'],
            ['threshold' => 14.0, 'label' => 'Bien'],
            ['threshold' => 12.0, 'label' => 'Assez bien'],
            ['threshold' => 10.0, 'label' => 'Passable'],
        ],
        'fail_label' => 'Insuffisant',
    ];

    /**
     * Une ecole primaire note sur dix jusqu'au CE1 et ne decerne pas de
     * mention : lui imposer « Tres bien » a 16 sur 20 n'aurait aucun sens.
     *
     * @var array<string, array<string, mixed>>
     */
    private const BY_TYPE = [
        'PRIMAIRE' => [
            'mentions' => [
                ['threshold' => 8.0, 'label' => 'Acquis'],
                ['threshold' => 6.0, 'label' => 'En cours d acquisition'],
            ],
            'fail_label' => 'Non acquis',
        ],
        'SUPERIEUR' => [
            'mentions' => [
                ['threshold' => 16.0, 'label' => 'Tres bien'],
                ['threshold' => 14.0, 'label' => 'Bien'],
                ['threshold' => 12.0, 'label' => 'Assez bien'],
                ['threshold' => 10.0, 'label' => 'Passable'],
            ],
            'fail_label' => 'Ajourne',
        ],
    ];

    /** @param  array<string, mixed>  $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Regles d'un etablissement, lues dans sa configuration.
     *
     * @param  array<string, mixed>|null  $tenant  ligne de la table tenants
     */
    public static function forTenant(?array $tenant): self
    {
        $type = (string) ($tenant['type'] ?? '');
        $values = array_merge(self::DEFAULTS, self::BY_TYPE[$type] ?? []);

        $config = [];

        if (is_string($tenant['config_json'] ?? null) && $tenant['config_json'] !== '') {
            $decoded = json_decode((string) $tenant['config_json'], true);
            $config = is_array($decoded['calculation'] ?? null) ? $decoded['calculation'] : [];
        }

        return new self(self::sanitize($values, $config));
    }

    /** @param  array<string, mixed>  $config */
    public static function fromArray(array $config, string $type = 'COLLEGE'): self
    {
        return new self(self::sanitize(array_merge(self::DEFAULTS, self::BY_TYPE[$type] ?? []), $config));
    }

    /**
     * Retient les reglages valides, ecarte les autres.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function sanitize(array $defaults, array $config): array
    {
        $values = $defaults;

        // Un bareme doit rester un nombre positif : a zero, toute note
        // deviendrait une division par zero.
        if (isset($config['scale']) && is_numeric($config['scale']) && (float) $config['scale'] > 0) {
            $values['scale'] = (float) $config['scale'];
        }

        // Le seuil de reussite doit tenir dans le bareme : au-dela, personne
        // ne passe jamais.
        if (isset($config['pass_mark']) && is_numeric($config['pass_mark'])) {
            $mark = (float) $config['pass_mark'];

            if ($mark > 0 && $mark <= $values['scale']) {
                $values['pass_mark'] = $mark;
            }
        }

        if (isset($config['rounding']) && in_array((int) $config['rounding'], [0, 1, 2], true)) {
            $values['rounding'] = (int) $config['rounding'];
        }

        if (isset($config['unjustified_absence'])
            && in_array($config['unjustified_absence'], [self::ABSENCE_ZERO, self::ABSENCE_IGNORED], true)) {
            $values['unjustified_absence'] = $config['unjustified_absence'];
        }

        if (isset($config['fail_label']) && is_string($config['fail_label']) && trim($config['fail_label']) !== '') {
            $values['fail_label'] = trim($config['fail_label']);
        }

        $mentions = self::sanitizeMentions($config['mentions'] ?? null, $values['scale']);

        if ($mentions !== []) {
            $values['mentions'] = $mentions;
        }

        return $values;
    }

    /**
     * Seuils de mention, ordonnes du plus haut au plus bas.
     *
     * Des seuils en desordre attribueraient la premiere mention rencontree,
     * donc la mauvaise : c'est le tri qui rend la lecture correcte, pas la
     * saisie.
     *
     * @return list<array{threshold: float, label: string}>
     */
    private static function sanitizeMentions(mixed $raw, float $scale): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $mentions = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['threshold'], $entry['label'])) {
                continue;
            }

            $threshold = (float) $entry['threshold'];
            $label = trim((string) $entry['label']);

            if ($label === '' || $threshold <= 0 || $threshold > $scale) {
                continue;
            }

            $mentions[] = ['threshold' => $threshold, 'label' => $label];
        }

        usort($mentions, static fn (array $a, array $b): int => $b['threshold'] <=> $a['threshold']);

        return $mentions;
    }

    public function scale(): float
    {
        return (float) $this->values['scale'];
    }

    public function passMark(): float
    {
        return (float) $this->values['pass_mark'];
    }

    public function rounding(): int
    {
        return (int) $this->values['rounding'];
    }

    /** Une absence non justifiee compte-t-elle zero, ou est-elle ignoree ? */
    public function countsUnjustifiedAbsenceAsZero(): bool
    {
        return $this->values['unjustified_absence'] === self::ABSENCE_ZERO;
    }

    /** @return list<array{threshold: float, label: string}> */
    public function mentions(): array
    {
        return $this->values['mentions'];
    }

    public function failLabel(): string
    {
        return (string) $this->values['fail_label'];
    }

    /** Arrondit une moyenne selon la precision retenue. */
    public function round(float $average): float
    {
        return round($average, $this->rounding());
    }

    /** Ramene une note brute sur le bareme de l'etablissement. */
    public function normalize(float $raw, float $maxValue): float
    {
        if ($maxValue <= 0) {
            return 0.0;
        }

        return $raw * $this->scale() / $maxValue;
    }

    public function isPass(float $average): bool
    {
        return $average >= $this->passMark();
    }

    /** Mention correspondant a une moyenne. */
    public function mention(float $average): string
    {
        foreach ($this->mentions() as $mention) {
            if ($average >= $mention['threshold']) {
                return $mention['label'];
            }
        }

        return $this->failLabel();
    }

    /** @return array<string, mixed> forme rangee dans config_json */
    public function toArray(): array
    {
        return $this->values;
    }
}
