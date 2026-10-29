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
        'public_primary.exams.catalogue' => [
            'default' => [
                'cep' => ['label_fr' => 'Certificat d\'études primaires (CEP)', 'label_en' => 'First School Leaving Certificate, French (CEP)', 'subsystem' => 'francophone', 'levels' => ['CM2'], 'kind' => 'certificate'],
                'fslc' => ['label_fr' => 'First School Leaving Certificate (FSLC)', 'label_en' => 'First School Leaving Certificate (FSLC)', 'subsystem' => 'anglophone', 'levels' => ['CLASS6'], 'kind' => 'certificate'],
                'sixth' => ['label_fr' => 'Concours d\'entrée en 6e', 'label_en' => 'Entrance examination into Form 1 (French)', 'subsystem' => 'francophone', 'levels' => ['CM2'], 'kind' => 'competition'],
                'common_entrance' => ['label_fr' => 'Common Entrance', 'label_en' => 'Common Entrance', 'subsystem' => 'anglophone', 'levels' => ['CLASS6'], 'kind' => 'competition'],
            ],
            'status' => 'cdc_default',
            'source' => 'EX-EXA-01, 02 : CEP en CM2 (francophone), FSLC en Class 6 (anglophone), entrée en 6e et Common Entrance ; classe d\'examen paramétrée par sous-système (spec §3).',
        ],
        'public_primary.exams.subjects' => [
            'default' => [], 'status' => 'to_confirm',
            'source' => 'Liste officielle des épreuves du CEP et du FSLC non publiée en ligne (recherche MINEDUB du 23/10/2026, §3) : vide, à obtenir du MINEDUB.',
        ],
        'public_primary.exams.coefficients' => [
            'default' => [], 'status' => 'to_confirm',
            'source' => 'Coefficients des épreuves non publiés (recherche MINEDUB, §3) : l\'application ne calcule aucune moyenne ; seuls les résultats officiels sont importés.',
        ],
        'public_primary.exams.pass_mark' => [
            'default' => null, 'status' => 'to_confirm',
            'source' => 'Note d\'admission non publiée (recherche MINEDUB, §3) : aucune ; le résultat (admis, ajourné, absent) vient de l\'import officiel (API-10).',
        ],
        'public_primary.exams.birth_act_alert_month' => [
            'default' => 1, 'status' => 'cdc_default',
            'source' => 'RG-EXA-1, NOT-08 : le directeur et les familles sont alertés dès janvier de l\'année d\'examen (mois 1 par défaut).',
        ],
        'public_primary.exams.functions' => [
            'default' => [
                'jury_president' => ['label_fr' => 'Président de jury', 'label_en' => 'Jury chair'],
                'mission_officer' => ['label_fr' => 'Chargé de mission', 'label_en' => 'Mission officer'],
                'centre_head' => ['label_fr' => 'Chef de centre', 'label_en' => 'Centre head'],
                'sub_centre_head' => ['label_fr' => 'Chef de sous-centre', 'label_en' => 'Sub-centre head'],
                'rapporteur' => ['label_fr' => 'Rapporteur', 'label_en' => 'Rapporteur'],
                'secretariat_head' => ['label_fr' => 'Chef de secrétariat', 'label_en' => 'Head of secretariat'],
                'pe_coordinator' => ['label_fr' => 'Coordonnateur d\'EPS', 'label_en' => 'PE coordinator'],
                'marking_room_head' => ['label_fr' => 'Chef de salle de correction', 'label_en' => 'Marking room head'],
                'pe_workshop_head' => ['label_fr' => 'Chef d\'atelier d\'EPS', 'label_en' => 'PE workshop head'],
                'secretariat_member' => ['label_fr' => 'Membre de secrétariat', 'label_en' => 'Secretariat member'],
                'marker' => ['label_fr' => 'Correcteur', 'label_en' => 'Marker'],
            ],
            'status' => 'official',
            'source' => 'Décret n° 2010/1738/PM du 7 juin 2010 (indemnités et vacations des examens du MINEDUB : CEP, FSLC, CAPIEMP, Common Entrance, ENIEG), recherche MINEDUB du 23/10/2026, §3.',
        ],
        'public_primary.exams.vacation_rates' => [
            'default' => ['half_day' => 1500, 'copy' => 60, 'director_per_candidate' => 50, 'per_function' => []],
            'status' => 'official',
            'source' => 'Décret n° 2010/1738/PM : vacation 1 500 FCFA par demi-journée, correction 60 FCFA la copie (CEP, FSLC, Common Entrance), indemnité du directeur d\'école 50 FCFA par candidat ; montants par fonction (per_function) à vérifier sur le texte intégral.',
        ],
        'public_primary.exams.attestation_signer' => [
            'default' => 'pub_departmental_delegate', 'status' => 'cdc_default',
            'source' => 'DOC-16 et Q17 : attestation préparée par l\'application, signée à la main par le délégué départemental (R06) qui en saisit le statut « signé ».',
        ],
    ];

    public function __construct(private readonly TerritorySettings $territorial, private readonly SettingsReader $settings)
    {
    }

    public function value(string $tenantId, string $establishmentId, string $key): mixed
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Paramètre inconnu : ' . $key);
        }
        $default = self::DEFAULTS[$key]['default'];
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
