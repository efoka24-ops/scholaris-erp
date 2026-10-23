<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;

/**
 * Règles de calcul déclaratives d'une année (EX-S09). Une règle n'existe que si le moteur l'applique réellement.
 * Une valeur aberrante lue dans les paramètres retombe sur le défaut ; une définition envoyée à l'API est refusée.
 *
 * Extensions pour les applications (valeurs par défaut = comportement historique) : stratégie de calcul et ses options
 * (S6), étiquettes de décision paramétrables (S9), effets déclaratifs des codes d'absence (S1).
 */
final class RuleSet
{
    public const ZERO = 'zero';
    public const EXCLUDED = 'excluded';
    public const RETAKE = 'retake';
    public const BY_COEFFICIENT = 'coefficient';
    public const BY_CREDITS = 'credits';
    public const STANDARD = 'standard';

    /** Décisions du moteur standard (code => libellé, admission ou non) ; libellés remplaçables, liste extensible. */
    public const DEFAULT_DECISIONS = [
        'admitted' => ['label' => 'Admis', 'passing' => true],
        'compensated' => ['label' => 'Admis par compensation', 'passing' => true],
        'resit' => ['label' => 'Rattrapage', 'passing' => false],
        'adjourned' => ['label' => 'Ajourné', 'passing' => false],
    ];

    private const CODE = '/^[a-z][a-z0-9_]{1,39}$/';
    private const ABSENCE_CODE = '/^[a-z][a-z0-9_]{0,11}$/';

    /**
     * @param list<array{min: float, label: string}> $mentions triées de la plus haute à la plus basse
     * @param list<array{label: string, min_average?: float, below_average?: float, min_unjustified?: int}> $councilProposals première correspondance retenue
     * @param array<string, mixed> $strategyOptions options propres à la stratégie
     * @param array<string, array{label: string, passing: bool}> $decisions étiquettes de décision par code
     * @param array<string, string> $absenceEffects effet déclaré par code d'absence (zero, excluded, retake)
     */
    public function __construct(
        public readonly int $version,
        public readonly float $scale,
        public readonly float $passMark,
        public readonly int $rounding,
        public readonly string $unjustifiedAbsence,
        public readonly string $weighting,
        public readonly array $mentions,
        public readonly array $councilProposals,
        public readonly float $compensationFrom,
        public readonly float $capitalizeFrom,
        public readonly float $resitFrom,
        public readonly string $strategy = self::STANDARD,
        public readonly array $strategyOptions = [],
        public readonly array $decisions = self::DEFAULT_DECISIONS,
        public readonly array $absenceEffects = [],
    ) {
    }

    public static function defaults(int $version = 0): self
    {
        return new self(
            $version,
            20.0,
            10.0,
            2,
            self::ZERO,
            self::BY_COEFFICIENT,
            [
                ['min' => 16.0, 'label' => 'Très bien'],
                ['min' => 14.0, 'label' => 'Bien'],
                ['min' => 12.0, 'label' => 'Assez bien'],
                ['min' => 10.0, 'label' => 'Passable'],
                ['min' => 0.0, 'label' => 'Doit fournir plus d\'efforts'],
            ],
            [
                ['label' => 'Blâme', 'min_unjustified' => 10],
                ['label' => 'Félicitations', 'min_average' => 16.0],
                ['label' => 'Tableau d\'honneur', 'min_average' => 14.0],
                ['label' => 'Encouragements', 'min_average' => 12.0],
                ['label' => 'Avertissement travail', 'below_average' => 8.0],
            ],
            8.0,
            10.0,
            8.0,
        );
    }

    /**
     * Applique une définition partielle par-dessus les valeurs de base ; refuse ce qui est incohérent.
     *
     * @param array<string, mixed> $definition
     * @throws InvalidArgumentException message en français destiné à l'utilisateur
     */
    public function with(array $definition, int $version): self
    {
        $known = [
            'scale', 'pass_mark', 'rounding', 'unjustified_absence', 'weighting', 'mentions', 'council_proposals', 'compensation_from', 'capitalize_from', 'resit_from',
            'strategy', 'strategy_options', 'decision_labels', 'absence_effects',
        ];
        $unknown = array_values(array_diff(array_keys($definition), $known));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Règle inconnue : ' . implode(', ', $unknown) . '. Règles possibles : ' . implode(', ', $known) . '.');
        }

        $scale = self::number($definition, 'scale', $this->scale, 1.0, 1000.0);
        $passMark = self::number($definition, 'pass_mark', $this->passMark, 0.0, $scale);
        $rounding = (int) self::number($definition, 'rounding', (float) $this->rounding, 0.0, 4.0);
        $absence = $definition['unjustified_absence'] ?? $this->unjustifiedAbsence;
        if (!in_array($absence, [self::ZERO, self::EXCLUDED], true)) {
            throw new InvalidArgumentException('unjustified_absence doit valoir « zero » ou « excluded ».');
        }
        $weighting = $definition['weighting'] ?? $this->weighting;
        if (!in_array($weighting, [self::BY_COEFFICIENT, self::BY_CREDITS], true)) {
            throw new InvalidArgumentException('weighting doit valoir « coefficient » ou « credits ».');
        }

        return new self(
            $version,
            $scale,
            $passMark,
            $rounding,
            (string) $absence,
            (string) $weighting,
            array_key_exists('mentions', $definition) ? self::mentionsFrom($definition['mentions'], $scale) : $this->mentions,
            array_key_exists('council_proposals', $definition) ? self::proposalsFrom($definition['council_proposals']) : $this->councilProposals,
            self::number($definition, 'compensation_from', $this->compensationFrom, 0.0, $scale),
            self::number($definition, 'capitalize_from', $this->capitalizeFrom, 0.0, $scale),
            self::number($definition, 'resit_from', $this->resitFrom, 0.0, $scale),
            array_key_exists('strategy', $definition) ? self::strategyFrom($definition['strategy']) : $this->strategy,
            array_key_exists('strategy_options', $definition) ? self::optionsFrom($definition['strategy_options']) : $this->strategyOptions,
            array_key_exists('decision_labels', $definition) ? self::decisionsFrom($definition['decision_labels'], $this->decisions) : $this->decisions,
            array_key_exists('absence_effects', $definition) ? self::effectsFrom($definition['absence_effects'], $this->absenceEffects) : $this->absenceEffects,
        );
    }

    /**
     * Lecture tolérante (paramètres de l'établissement) : toute valeur aberrante est ignorée au profit du défaut.
     *
     * @param array<string, mixed> $definition
     */
    public function withLenient(array $definition, int $version): self
    {
        $current = $this->with([], $version);
        if (array_key_exists('scale', $definition)) {
            $definition = ['scale' => $definition['scale']] + $definition;
        }
        foreach ($definition as $key => $value) {
            try {
                $current = $current->with([$key => $value], $version);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $current;
    }

    /** Libellé d'une décision (code admitted, compensated, resit, adjourned ou déclaré) ; le code s'il est inconnu. */
    public function decisionLabel(string $code): string
    {
        return $this->decisions[$code]['label'] ?? $code;
    }

    /** @return list<string> libellés de toutes les décisions déclarées */
    public function decisionLabels(): array
    {
        return array_values(array_map(static fn (array $d): string => $d['label'], $this->decisions));
    }

    /** @return list<string> libellés des décisions qui valent admission */
    public function passingLabels(): array
    {
        return array_values(array_map(static fn (array $d): string => $d['label'], array_filter($this->decisions, static fn (array $d): bool => $d['passing'])));
    }

    /**
     * Effet d'un code d'absence sur le calcul : « justified » est exclu, « unjustified » suit unjustified_absence, sauf
     * effet déclaré ; un code inconnu est exclu (jamais un zéro non voulu).
     */
    public function absenceEffect(string $code): string
    {
        if (isset($this->absenceEffects[$code])) {
            return $this->absenceEffects[$code];
        }

        return $code === 'unjustified' ? $this->unjustifiedAbsence : self::EXCLUDED;
    }

    /** Vue publique remise aux stratégies de calcul et aux applications. */
    public function toCalculationRules(): CalculationRules
    {
        $effects = $this->absenceEffects + ['justified' => self::EXCLUDED, 'unjustified' => $this->unjustifiedAbsence];

        return new CalculationRules(
            $this->version,
            $this->strategy,
            $this->scale,
            $this->passMark,
            $this->rounding,
            $this->mentions,
            $this->compensationFrom,
            $this->capitalizeFrom,
            $this->resitFrom,
            $this->strategyOptions,
            array_map(static fn (array $d): string => $d['label'], $this->decisions),
            array_keys(array_filter($this->decisions, static fn (array $d): bool => $d['passing'])),
            $effects,
        );
    }

    /** Règles reconstruites depuis leur vue publique (simulation demandée par une application). */
    public static function fromCalculationRules(CalculationRules $rules): self
    {
        $decisions = [];
        foreach ($rules->decisionLabels as $code => $label) {
            $decisions[$code] = ['label' => $label, 'passing' => in_array($code, $rules->passingDecisions, true)];
        }
        $effects = [];
        foreach ($rules->absenceEffects as $code => $effect) {
            if (in_array($effect, [self::ZERO, self::EXCLUDED, self::RETAKE], true)) {
                $effects[$code] = $effect;
            }
        }
        $unjustified = ($effects['unjustified'] ?? self::ZERO) === self::EXCLUDED ? self::EXCLUDED : self::ZERO;
        $defaults = self::defaults();

        return new self(
            $rules->version,
            $rules->scale,
            $rules->passMark,
            $rules->rounding,
            $unjustified,
            $defaults->weighting,
            $rules->mentions,
            $defaults->councilProposals,
            $rules->compensationFrom,
            $rules->capitalizeFrom,
            $rules->resitFrom,
            $rules->strategy,
            $rules->options,
            $decisions === [] ? self::DEFAULT_DECISIONS : $decisions,
            $effects,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'scale' => $this->scale,
            'pass_mark' => $this->passMark,
            'rounding' => $this->rounding,
            'unjustified_absence' => $this->unjustifiedAbsence,
            'weighting' => $this->weighting,
            'mentions' => $this->mentions,
            'council_proposals' => $this->councilProposals,
            'compensation_from' => $this->compensationFrom,
            'capitalize_from' => $this->capitalizeFrom,
            'resit_from' => $this->resitFrom,
            'strategy' => $this->strategy,
            'strategy_options' => $this->strategyOptions,
            'decision_labels' => $this->decisions,
            'absence_effects' => $this->absenceEffects,
        ];
    }

    /** @param array<string, mixed> $source */
    private static function number(array $source, string $key, float $default, float $min, float $max): float
    {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        $value = $source[$key];
        if ((!is_int($value) && !is_float($value)) || $value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s doit être un nombre compris entre %s et %s.', $key, Num::format($min), Num::format($max)));
        }

        return (float) $value;
    }

    /** @return list<array{min: float, label: string}> */
    private static function mentionsFrom(mixed $raw, float $scale): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('mentions doit être une liste non vide de {min, label}.');
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['label']) || !is_string($item['label']) || trim($item['label']) === ''
                || !isset($item['min']) || (!is_int($item['min']) && !is_float($item['min'])) || $item['min'] < 0 || $item['min'] > $scale) {
                throw new InvalidArgumentException('Chaque mention doit avoir un libellé et un seuil « min » compris entre 0 et le barème.');
            }
            $out[] = ['min' => (float) $item['min'], 'label' => trim($item['label'])];
        }
        usort($out, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);

        return $out;
    }

    /** @return list<array{label: string, min_average?: float, below_average?: float, min_unjustified?: int}> */
    private static function proposalsFrom(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException('council_proposals doit être une liste.');
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['label']) || !is_string($item['label']) || trim($item['label']) === '') {
                throw new InvalidArgumentException('Chaque proposition du conseil doit avoir un libellé.');
            }
            $rule = ['label' => trim($item['label'])];
            foreach (['min_average', 'below_average'] as $key) {
                if (isset($item[$key])) {
                    if (!is_int($item[$key]) && !is_float($item[$key])) {
                        throw new InvalidArgumentException($key . ' doit être un nombre.');
                    }
                    $rule[$key] = (float) $item[$key];
                }
            }
            if (isset($item['min_unjustified'])) {
                if (!is_int($item['min_unjustified']) || $item['min_unjustified'] < 0) {
                    throw new InvalidArgumentException('min_unjustified doit être un entier positif.');
                }
                $rule['min_unjustified'] = $item['min_unjustified'];
            }
            $out[] = $rule;
        }

        return $out;
    }

    private static function strategyFrom(mixed $raw): string
    {
        if (!is_string($raw) || preg_match(self::CODE, $raw) !== 1) {
            throw new InvalidArgumentException('strategy doit être un code de stratégie (minuscules, chiffres, « _ »), par exemple « standard ».');
        }

        return $raw;
    }

    /** @return array<string, mixed> */
    private static function optionsFrom(mixed $raw): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > 64) {
            throw new InvalidArgumentException('strategy_options doit être un objet d\'au plus 64 options.');
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Étiquettes de décision : {code: "libellé"} ou {code: {label, passing}} ; les codes du moteur standard restent
     * présents (libellé remplaçable), d'autres s'ajoutent (admis avec dettes, redouble, exclu...).
     *
     * @param array<string, array{label: string, passing: bool}> $current
     * @return array<string, array{label: string, passing: bool}>
     */
    private static function decisionsFrom(mixed $raw, array $current): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidArgumentException('decision_labels doit être un objet {code: libellé} ou {code: {label, passing}}.');
        }
        $out = $current;
        foreach ($raw as $code => $value) {
            $code = (string) $code;
            if (preg_match(self::CODE, $code) !== 1) {
                throw new InvalidArgumentException('Code de décision invalide : « ' . $code . ' » (minuscules, chiffres, « _ »).');
            }
            $label = is_array($value) ? ($value['label'] ?? null) : $value;
            if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > 60) {
                throw new InvalidArgumentException('Le libellé de la décision « ' . $code . ' » doit être un texte de 1 à 60 caractères.');
            }
            $passing = is_array($value) && array_key_exists('passing', $value) ? $value['passing'] : ($out[$code]['passing'] ?? false);
            if (!is_bool($passing)) {
                throw new InvalidArgumentException('passing de la décision « ' . $code . ' » doit valoir true ou false.');
            }
            $out[$code] = ['label' => trim($label), 'passing' => $passing];
        }
        $labels = array_map(static fn (array $d): string => $d['label'], $out);
        if (count(array_unique($labels)) !== count($labels)) {
            throw new InvalidArgumentException('Deux décisions ne peuvent pas porter le même libellé.');
        }

        return $out;
    }

    /**
     * @param array<string, string> $current
     * @return array<string, string>
     */
    private static function effectsFrom(mixed $raw, array $current): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidArgumentException('absence_effects doit être un objet {code: effet}, par exemple {"na": "zero", "justified": "retake"}.');
        }
        $out = $current;
        foreach ($raw as $code => $effect) {
            $code = (string) $code;
            if (preg_match(self::ABSENCE_CODE, $code) !== 1) {
                throw new InvalidArgumentException('Code d\'absence invalide : « ' . $code . ' » (12 caractères au plus, minuscules, chiffres, « _ »).');
            }
            if (!in_array($effect, [self::ZERO, self::EXCLUDED, self::RETAKE], true)) {
                throw new InvalidArgumentException('L\'effet du code « ' . $code . ' » doit valoir « zero », « excluded » ou « retake ».');
            }
            $out[$code] = $effect;
        }

        return $out;
    }
}
