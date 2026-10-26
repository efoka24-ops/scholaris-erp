<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritorySettings;

/**
 * Paramètres de la Primaire publique (RG-TER-6, PP16) : AUCUNE règle officielle n'est codée en dur. Chaque clé a une valeur de départ
 * et sa source ; la valeur effective vient d'abord des paramètres territoriaux (national > région > … > école, sous réserve des
 * verrous), puis des paramètres ordinaires (client, plateforme), enfin de la valeur de départ ci-dessous.
 *
 * Statuts : `official` (texte officiel publié), `cdc_default` (valeur par défaut du cahier des charges, à valider par l'Inspection
 * générale, Q8/Q9), `to_confirm` (aucun texte publié : valeur prudente à confirmer).
 */
final class Rules
{
    /** @var array<string, array{default: mixed, status: string, source: string}> */
    public const DEFAULTS = [
        'public_primary.evaluation.scale' => [
            'default' => [
                ['code' => 'A', 'label_fr' => 'Acquis', 'label_en' => 'Achieved'],
                ['code' => 'ECA', 'label_fr' => 'En cours d\'acquisition', 'label_en' => 'In progress'],
                ['code' => 'NA', 'label_fr' => 'Non acquis', 'label_en' => 'Not achieved'],
            ],
            'status' => 'to_confirm',
            'source' => 'Bulletin à trois mentions depuis 2018-2019 (Cameroon Tribune, janv. 2019, cité par le CDC) ; barème officiel non publié en ligne (recherche MINEDUB du 23/10/2026, §4).',
        ],
        'public_primary.evaluation.occasions' => [
            'default' => 3, 'status' => 'cdc_default',
            'source' => 'CDC public, « Paramètres du primaire » : 3 occasions de vérification par critère (curriculum 2018), à valider (Q8).',
        ],
        'public_primary.evaluation.mastery_min_success' => [
            'default' => 2, 'status' => 'cdc_default',
            'source' => 'CDC public : critère maîtrisé si au moins 2 occasions réussies sur 3 (règle des deux tiers), à valider (Q8).',
        ],
        'public_primary.evaluation.mention_rule' => [
            'default' => ['all' => 'A', 'some' => 'ECA', 'none' => 'NA'], 'status' => 'cdc_default',
            'source' => 'CDC public : A si tous les critères minimaux sont maîtrisés, ECA si au moins un, NA si aucun.',
        ],
        'public_primary.evaluation.perfection_only_if_a' => [
            'default' => true, 'status' => 'cdc_default',
            'source' => 'CDC public : le critère de perfectionnement n\'intervient que si la mention est A.',
        ],
        'public_primary.evaluation.trimester_mention' => [
            'default' => 'last_unit', 'status' => 'cdc_default',
            'source' => 'CDC public : mention trimestrielle = mention de la dernière unité du trimestre.',
        ],
        'public_primary.evaluation.annual_mention' => [
            'default' => 'final_integration_else_last_unit', 'status' => 'cdc_default',
            'source' => 'CDC public : mention annuelle = évaluation d\'intégration de fin d\'année, à défaut dernière unité.',
        ],
        'public_primary.evaluation.rank_enabled' => [
            'default' => false, 'status' => 'cdc_default',
            'source' => 'RG-EVA-1 : aucun rang au primaire (paramètre national désactivé).',
        ],
        'public_primary.evaluation.units_per_year' => [
            'default' => 8, 'status' => 'cdc_default',
            'source' => 'Curriculum 2018 niveau 1 (8 centres d\'intérêt d\'environ un mois), repris par le CDC (EX-EVA-02).',
        ],
        'public_primary.evaluation.evaluation_week_days' => [
            'default' => 7, 'status' => 'cdc_default',
            'source' => 'CDC public : dernière semaine de chaque unité pour l\'intégration, l\'évaluation et la remédiation.',
        ],
        'public_primary.evaluation.numeric_scores_enabled' => [
            'default' => false, 'status' => 'to_confirm',
            'source' => 'EX-EVA-08 (Should) : notes chiffrées optionnelles (épreuves blanches) ; désactivées par défaut.',
        ],
        'public_primary.evaluation.numeric_scale_max' => [
            'default' => 20, 'status' => 'to_confirm',
            'source' => 'Barème des notes chiffrées non publié (recherche MINEDUB §4) : /20 par défaut, à confirmer.',
        ],
        'public_primary.remediation.minutes_per_week' => [
            'default' => 60, 'status' => 'official',
            'source' => 'Arrêté conjoint du 14 août 2026 (calendrier 2026-2027) : au moins 1 h par semaine et par discipline fondamentale.',
        ],
        'public_primary.remediation.forms' => [
            'default' => ['need_group', 'differentiated_sheet', 'peer_tutoring'], 'status' => 'official',
            'source' => 'Arrêté conjoint du 14 août 2026 : groupes de besoin, fiches différenciées, tutorat par les pairs ; registre et fiche de suivi obligatoires.',
        ],
        'public_primary.promotion.policy' => [
            'default' => ['version' => 'example-cdc-2026-10', 'na_for_remediation' => 1, 'na_for_council' => 2], 'status' => 'cdc_default',
            'source' => 'CDC public, « Politique de passage (exemple à valider) » : disciplines clés = référentiel (is_key_discipline), Q9.',
        ],
        'public_primary.promotion.appeal_days' => [
            'default' => 8, 'status' => 'to_confirm',
            'source' => 'EX-PAS-03 : délai de recours paramétré, aucune valeur publiée ; 8 jours par défaut, à confirmer (Q13).',
        ],
    ];

    public function __construct(private readonly TerritorySettings $territorial, private readonly SettingsReader $settings)
    {
    }

    public function value(string $tenantId, string $establishmentId, string $key): mixed
    {
        $default = self::DEFAULTS[$key]['default'] ?? throw new \InvalidArgumentException('Paramètre inconnu : ' . $key);
        $ordinary = $this->settings->value($tenantId, $establishmentId, $key, $default);
        try {
            return $this->territorial->value($tenantId, $establishmentId, $key, $ordinary);
        } catch (HttpException) {
            return $ordinary;
        }
    }

    public function int(string $tenantId, string $establishmentId, string $key): int
    {
        $value = $this->value($tenantId, $establishmentId, $key);

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key]['default'];
    }

    public function bool(string $tenantId, string $establishmentId, string $key): bool
    {
        $value = $this->value($tenantId, $establishmentId, $key);

        return is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true);
    }

    public function string(string $tenantId, string $establishmentId, string $key): string
    {
        $value = $this->value($tenantId, $establishmentId, $key);

        $default = self::DEFAULTS[$key]['default'];

        return is_string($value) ? $value : (is_string($default) ? $default : '');
    }

    /** @return array<mixed> */
    public function array(string $tenantId, string $establishmentId, string $key): array
    {
        $value = $this->value($tenantId, $establishmentId, $key);

        return is_array($value) ? $value : (array) self::DEFAULTS[$key]['default'];
    }

    /** @return list<array{key: string, value: mixed, default: mixed, status: string, source: string}> */
    public function describe(string $tenantId, string $establishmentId): array
    {
        $rows = [];
        foreach (self::DEFAULTS as $key => $meta) {
            $rows[] = ['key' => $key, 'value' => $this->value($tenantId, $establishmentId, $key), 'default' => $meta['default'], 'status' => $meta['status'], 'source' => $meta['source']];
        }

        return $rows;
    }
}
