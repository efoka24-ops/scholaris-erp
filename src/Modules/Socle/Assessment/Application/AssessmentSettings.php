<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Domain\Lifecycle;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Paramètres du module (clés assessment.*), lus avec leur héritage plateforme, client, établissement.
 * Valeurs aberrantes : défaut du socle, jamais une règle fausse appliquée. Sans paramètre, le comportement est
 * celui du socle historique (secondaire, primaire, maternelle).
 *
 *  - assessment.lifecycle : cycle des notes déclaré par l'application (voir Lifecycle) ;
 *  - assessment.scale, pass_mark, rounding, unjustified_absence, weighting, mentions : règles de base du calcul ;
 *  - assessment.strategy, strategy_options, decision_labels : stratégie de calcul et étiquettes de décision (S6, S9) ;
 *  - assessment.absence_codes : codes d'absence supplémentaires et leur effet {code: {effect, label, aliases}} (S1) ;
 *  - assessment.step : pas de saisie des notes (0,25 = quart de point), surchargé par épreuve (S2) ;
 *  - assessment.segregate_lock : true (ou liste d'états cibles) = qui a saisi une note ne verrouille pas l'épreuve (S4) ;
 *  - assessment.correction.chain : étapes d'approbation d'une correction [{permission, label, require_recent_2fa}] (S3) ;
 *  - assessment.deliberation.* : gardes de délibération (S8) ;
 *  - assessment.quorum_percent : part minimale des membres du jury présents (50 par défaut).
 */
final class AssessmentSettings
{
    /** Codes d'absence du socle : toujours présents. */
    public const BUILT_IN_ABSENCES = [
        'justified' => ['label' => 'Absence justifiée', 'aliases' => ['ABS', 'ABJ']],
        'unjustified' => ['label' => 'Absence non justifiée', 'aliases' => ['ABI']],
    ];

    public const SIGNATURES_NONE = 'none';
    public const SIGNATURES_PRESIDENT = 'president';
    public const SIGNATURES_ALL = 'all_present';

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    public function lifecycle(string $tenantId, string $establishmentId): Lifecycle
    {
        $raw = $this->array($this->settings->value($tenantId, $establishmentId, 'assessment.lifecycle', null));

        return ($raw === null ? null : Lifecycle::fromArray($raw)) ?? Lifecycle::default();
    }

    /** Règles de base de l'établissement, avant toute version stockée pour l'année. */
    public function baseRules(string $tenantId, string $establishmentId): RuleSet
    {
        $definition = [];
        foreach (['scale', 'pass_mark', 'rounding', 'unjustified_absence', 'weighting', 'mentions', 'strategy', 'strategy_options', 'decision_labels'] as $key) {
            $value = $this->settings->value($tenantId, $establishmentId, 'assessment.' . $key, null);
            if ($value === null) {
                continue;
            }
            $definition[$key] = in_array($key, ['mentions', 'strategy_options', 'decision_labels'], true) ? $this->array($value) : $value;
        }
        $effects = [];
        foreach ($this->absenceCodes($tenantId, $establishmentId) as $code => $declared) {
            if ($declared['effect'] !== null) {
                $effects[$code] = $declared['effect'];
            }
        }
        if ($effects !== []) {
            $definition['absence_effects'] = $effects;
        }

        return RuleSet::defaults()->withLenient(array_filter($definition, static fn (mixed $v): bool => $v !== null), 0);
    }

    /**
     * Codes d'absence acceptés à la saisie : ceux du socle plus ceux déclarés par l'application, avec leur effet de
     * calcul déclaré (null = règle du socle) et leurs alias d'import CSV (en majuscules).
     *
     * @return array<string, array{effect: string|null, label: string, aliases: list<string>}>
     */
    public function absenceCodes(string $tenantId, string $establishmentId): array
    {
        $codes = [];
        foreach (self::BUILT_IN_ABSENCES as $code => $builtIn) {
            $codes[$code] = ['effect' => null, 'label' => $builtIn['label'], 'aliases' => $builtIn['aliases']];
        }
        $raw = $this->array($this->settings->value($tenantId, $establishmentId, 'assessment.absence_codes', null)) ?? [];
        foreach ($raw as $code => $declaration) {
            $code = (string) $code;
            if (preg_match('/^[a-z][a-z0-9_]{0,11}$/', $code) !== 1 || !is_array($declaration)) {
                continue;
            }
            $effect = $declaration['effect'] ?? null;
            if (!in_array($effect, [RuleSet::ZERO, RuleSet::EXCLUDED, RuleSet::RETAKE], true)) {
                $effect = null;
            }
            $aliases = $codes[$code]['aliases'] ?? [];
            foreach ((array) ($declaration['aliases'] ?? []) as $alias) {
                if (is_string($alias) && preg_match('/^[A-Za-z]{1,8}$/', $alias) === 1) {
                    $aliases[] = strtoupper($alias);
                }
            }
            $label = isset($declaration['label']) && is_string($declaration['label']) && trim($declaration['label']) !== '' ? trim($declaration['label']) : ($codes[$code]['label'] ?? $code);
            $codes[$code] = ['effect' => $effect, 'label' => $label, 'aliases' => array_values(array_unique($aliases))];
        }

        return $codes;
    }

    /** Types d'épreuve acceptés par défaut (tous ordres d'enseignement) ; l'établissement peut fixer sa liste. */
    public const DEFAULT_KINDS = ['cc', 'devoir', 'interrogation', 'composition', 'evaluation', 'examen', 'exam', 'td', 'tp', 'oral', 'projet', 'test', 'retake', 'resit'];

    /**
     * assessment.kinds : liste fermée des types d'épreuve (codes en minuscules). Absente ou vide : DEFAULT_KINDS.
     *
     * @return list<string>
     */
    public function kinds(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, 'assessment.kinds', null);
        $list = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $kinds = [];
        foreach ($list as $kind) {
            if (is_string($kind) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', trim($kind)) === 1) {
                $kinds[] = trim($kind);
            }
        }

        return $kinds === [] ? self::DEFAULT_KINDS : array_values(array_unique($kinds));
    }

    /** assessment.subject_ranks (SS4) : rang et statistiques par matière dans les résultats ; faux par défaut. */
    public function subjectRanks(string $tenantId, string $establishmentId): bool
    {
        return in_array($this->settings->value($tenantId, $establishmentId, 'assessment.subject_ranks', false), [true, 1, '1', 'true'], true);
    }

    /**
     * Type de document d'un bulletin (SS5) : assessment.report_card_types[<nature de la période>] s'il est déclaré
     * (ex. {"sequence": "secondary_report_sequence", "trimester": "...", "year": "..."}), sinon assessment.report_card_type,
     * sinon $default. Un code mal formé est ignoré.
     */
    public function reportCardType(string $tenantId, string $establishmentId, ?string $periodKind, string $default): string
    {
        $byKind = $this->array($this->settings->value($tenantId, $establishmentId, 'assessment.report_card_types', null)) ?? [];
        $candidates = [$periodKind === null ? null : ($byKind[$periodKind] ?? null), $this->settings->value($tenantId, $establishmentId, 'assessment.report_card_type', null)];
        foreach ($candidates as $type) {
            if (is_string($type) && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type) === 1) {
                return $type;
            }
        }

        return $default;
    }

    /** Pas de saisie de l'établissement (null = deux décimales au plus, règle historique). */
    public function step(string $tenantId, string $establishmentId): ?float
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.step', null);

        return (is_int($value) || is_float($value)) && $value > 0 && $value <= 10 ? (float) $value : null;
    }

    /** @return list<array{code: string, label_fr: string, label_en: string, sort_order: int}> */
    public function qualitativeScale(string $tenantId, string $establishmentId): array
    {
        $default = [
            ['code' => 'acquired', 'label_fr' => 'Acquis', 'label_en' => 'Acquired', 'sort_order' => 1],
            ['code' => 'in_progress', 'label_fr' => 'En cours', 'label_en' => 'In progress', 'sort_order' => 2],
            ['code' => 'not_acquired', 'label_fr' => 'Non acquis', 'label_en' => 'Not acquired', 'sort_order' => 3],
        ];
        $raw = $this->anyArray($this->settings->value($tenantId, $establishmentId, 'assessment.qualitative_scale', null));
        if ($raw === null || !array_is_list($raw) || $raw === []) {
            return $default;
        }
        $scale = [];
        $codes = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row) || !isset($row['code']) || !is_string($row['code']) || preg_match('/^[a-z][a-z0-9_]{1,19}$/', $row['code']) !== 1
                || !isset($row['label_fr'], $row['label_en']) || !is_string($row['label_fr']) || !is_string($row['label_en'])
                || trim($row['label_fr']) === '' || trim($row['label_en']) === '' || isset($codes[$row['code']])) {
                return $default;
            }
            $codes[$row['code']] = true;
            $scale[] = ['code' => $row['code'], 'label_fr' => trim($row['label_fr']), 'label_en' => trim($row['label_en']), 'sort_order' => isset($row['sort_order']) && is_numeric($row['sort_order']) ? (int) $row['sort_order'] : $index + 1];
        }
        usort($scale, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $scale;
    }

    /**
     * Séparation saisie / verrouillage (S4) : null = désactivée ; [] = toute transition qui sort de la saisie ;
     * sinon les états cibles concernés.
     *
     * @return list<string>|null
     */
    public function segregatedTargets(string $tenantId, string $establishmentId): ?array
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.segregate_lock', null);
        if ($value === true) {
            return [];
        }
        $list = $this->anyArray($value);
        if ($list === null || $list === [] || !array_is_list($list)) {
            return null;
        }

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * Étapes d'approbation d'une correction de note verrouillée (S3). Défaut : une seule approbation par
     * assessment.correction.approve, sans 2FA.
     *
     * @return list<array{permission: string, label: string, require_recent_2fa: bool}>
     */
    public function correctionChain(string $tenantId, string $establishmentId): array
    {
        $default = [['permission' => 'assessment.correction.approve', 'label' => 'Approbation', 'require_recent_2fa' => false]];
        $raw = $this->anyArray($this->settings->value($tenantId, $establishmentId, 'assessment.correction.chain', null));
        if ($raw === null || $raw === [] || !array_is_list($raw) || count($raw) > 5) {
            return $default;
        }
        $steps = [];
        foreach ($raw as $index => $step) {
            if (!is_array($step) || !isset($step['permission']) || !is_string($step['permission']) || preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $step['permission']) !== 1) {
                return $default;
            }
            $steps[] = [
                'permission' => $step['permission'],
                'label' => isset($step['label']) && is_string($step['label']) && trim($step['label']) !== '' ? trim($step['label']) : 'Étape ' . ($index + 1),
                'require_recent_2fa' => ($step['require_recent_2fa'] ?? false) === true,
            ];
        }

        return $steps;
    }

    /** RG-DEL-1 : ouverture seulement si toutes les notes concernées sont verrouillées. */
    public function deliberationRequiresLockedGrades(string $tenantId, string $establishmentId): bool
    {
        return $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.require_locked_grades', false) === true;
    }

    /** Signatures exigées avant publication : none (défaut), president, all_present. */
    public function deliberationSignatures(string $tenantId, string $establishmentId): string
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.signatures_required', self::SIGNATURES_NONE);

        return in_array($value, [self::SIGNATURES_PRESIDENT, self::SIGNATURES_ALL], true) ? (string) $value : self::SIGNATURES_NONE;
    }

    /** Étapes de délibération qui exigent une double authentification récente (true = clôture, signature, publication, réouverture). */
    public function deliberationRequires2fa(string $tenantId, string $establishmentId, string $stage): bool
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.require_2fa', false);
        if ($value === true) {
            return in_array($stage, [DeliberationGuard::CLOSE, DeliberationGuard::SIGN, DeliberationGuard::PUBLISH, DeliberationGuard::REOPEN], true);
        }
        $list = $this->anyArray($value);

        return $list !== null && in_array($stage, $list, true);
    }

    /** Vérification de l'empreinte SHA-256 des décisions avant signature et publication. */
    public function deliberationVerifiesFingerprint(string $tenantId, string $establishmentId): bool
    {
        return $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.verify_fingerprint', false) === true;
    }

    /** Résultats figés par une délibération close : aucun recalcul ne les réécrit (RG-DEL-4). */
    public function deliberationFreezesResults(string $tenantId, string $establishmentId): bool
    {
        return $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.freeze_results', false) === true;
    }

    /** Droit requis pour rouvrir une délibération close (défaut : assessment.correction.approve). */
    public function reopenPermission(string $tenantId, string $establishmentId): string
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.reopen_permission', null);

        return is_string($value) && preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $value) === 1 ? $value : 'assessment.correction.approve';
    }

    /**
     * Alerte à la réouverture (directeur général...) : modèle de message et personnes destinataires.
     *
     * @return array{template: string, person_ids: list<string>}|null
     */
    public function reopenAlert(string $tenantId, string $establishmentId): ?array
    {
        $raw = $this->array($this->settings->value($tenantId, $establishmentId, 'assessment.deliberation.reopen_alert', null));
        if ($raw === null || !isset($raw['person_ids']) || !is_array($raw['person_ids'])) {
            return null;
        }
        $persons = array_values(array_filter($raw['person_ids'], static fn (mixed $p): bool => is_string($p) && $p !== ''));
        if ($persons === []) {
            return null;
        }
        $template = isset($raw['template']) && is_string($raw['template']) && $raw['template'] !== '' ? $raw['template'] : 'assessment.deliberation_reopened';

        return ['template' => $template, 'person_ids' => $persons];
    }

    public function quorumPercent(string $tenantId, string $establishmentId): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'assessment.quorum_percent', 50);

        return is_int($value) && $value >= 1 && $value <= 100 ? $value : 50;
    }

    /** @return array<mixed>|null tableau quelconque (liste ou objet) */
    private function anyArray(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : null;
    }

    /** @return array<string, mixed>|null */
    private function array(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : null;
    }
}
