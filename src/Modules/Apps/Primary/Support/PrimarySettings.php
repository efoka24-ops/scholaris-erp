<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Paramètres de l'école primaire privée, lus par `/settings` (héritage plateforme > client > établissement). AUCUNE règle officielle
 * n'est codée en dur : chaque clé a une valeur de départ « à confirmer » par le client, avec sa source (question de
 * specs/003-application-primaire/questions.md ou texte du MINEDUB, specs/006-audit-primaire-maternelle/recherche-minedub.md).
 * L'école ou la plateforme la remplace par PUT /settings ; GET /primary/settings/defaults liste l'ensemble pour l'écran.
 */
final class PrimarySettings
{
    /** @var array<string, array{default: mixed, source: string, label: string}> */
    public const DEFAULTS = [
        'primary.trimesters' => ['default' => 3, 'source' => 'Q4 ; arrêté du 14 août 2026, art. 10 (trois trimestres)', 'label' => 'Nombre de trimestres de l\'année'],
        'primary.report_card.show_rank' => ['default' => true, 'source' => 'Q8', 'label' => 'Rang affiché au bulletin'],
        'primary.report_card.numeric_averages' => ['default' => true, 'source' => 'Q3, Q6 (moyennes chiffrées optionnelles)', 'label' => 'Moyennes chiffrées au bulletin (si des notes existent)'],
        'primary.report_card.annual' => ['default' => true, 'source' => 'Q2 (récapitulatif annuel au dernier trimestre)', 'label' => 'Récapitulatif annuel émis au dernier trimestre'],
        'primary.report_card.block_on_debt' => ['default' => false, 'source' => 'Q17', 'label' => 'Bulletin bloqué en cas d\'impayé'],
        'primary.report_card.locale' => ['default' => null, 'source' => 'Q1 (langue de la section du niveau si vide)', 'label' => 'Langue imposée des bulletins (fr, en ou vide)'],
        'primary.council.require_locked_sheets' => ['default' => true, 'source' => 'RG-PRI-4, RG-PRI-6', 'label' => 'Le conseil exige des feuilles de compétences verrouillées'],
        'primary.council.decision_labels' => ['default' => [
            'admitted' => ['fr' => 'Admis(e) en classe supérieure', 'en' => 'Promoted'],
            'adjourned' => ['fr' => 'Redouble', 'en' => 'Repeats the class'],
            'cycle_end' => ['fr' => 'Fin de cycle', 'en' => 'End of cycle'],
        ], 'source' => 'Q10', 'label' => 'Libellés des décisions de fin d\'année'],
        'primary.class_size_cap' => ['default' => 60, 'source' => 'arrêté du 14 août 2026, art. 14 (60 élèves par classe au plus au primaire)', 'label' => 'Plafond d\'élèves par classe'],
        'primary.remediation.weekly_minutes' => ['default' => 60, 'source' => 'arrêté du 14 août 2026 (au moins 1 h par semaine et par discipline fondamentale)', 'label' => 'Minutes de remédiation attendues par semaine et par discipline fondamentale'],
        'primary.remediation.forms' => ['default' => [
            'needs_group' => ['fr' => 'Groupe de besoin', 'en' => 'Needs group'],
            'differentiated_sheet' => ['fr' => 'Fiche différenciée', 'en' => 'Differentiated worksheet'],
            'peer_tutoring' => ['fr' => 'Tutorat par les pairs', 'en' => 'Peer tutoring'],
        ], 'source' => 'arrêté du 14 août 2026 (formes de remédiation)', 'label' => 'Formes de remédiation'],
        'primary.remediation.fundamental_subjects' => ['default' => [], 'source' => 'arrêté du 14 août 2026 (disciplines fondamentales : liste non publiée, saisie par l\'école)', 'label' => 'Codes des disciplines fondamentales'],
        'primary.exam.kinds' => ['default' => [
            'cep' => ['fr' => 'Certificat d\'études primaires (CEP)', 'en' => 'Primary school certificate (CEP)', 'subsystem' => 'fr'],
            'fslc' => ['fr' => 'First School Leaving Certificate (FSLC)', 'en' => 'First School Leaving Certificate (FSLC)', 'subsystem' => 'en'],
        ], 'source' => 'Q11 ; communiqué MINEDUB du 12 juin 2026 (CEP francophone, FSLC anglophone)', 'label' => 'Examens de fin de cycle'],
        'primary.exam.registration_fields' => ['default' => [
            ['code' => 'birth_place', 'fr' => 'Lieu de naissance', 'en' => 'Place of birth', 'required' => true],
            ['code' => 'father_name', 'fr' => 'Nom du père', 'en' => 'Father\'s name', 'required' => false],
            ['code' => 'mother_name', 'fr' => 'Nom de la mère', 'en' => 'Mother\'s name', 'required' => false],
            ['code' => 'school_id_card', 'fr' => 'Carte d\'identité scolaire ou « acceptance slip »', 'en' => 'School identity card or acceptance slip', 'required' => false],
        ], 'source' => 'Q11 ; format officiel non publié (recherche-minedub §3)', 'label' => 'Champs de la fiche d\'inscription à l\'examen'],
        'primary.exam.candidate_number_prefix' => ['default' => '', 'source' => 'Q11 (numéros attribués par l\'école ou l\'inspection)', 'label' => 'Préfixe des numéros de candidat attribués par l\'école'],
        'primary.alert.absences' => ['default' => 3, 'source' => 'spec §8 (demi-journées consécutives)', 'label' => 'Absences consécutives (demi-journées) avant alerte'],
        'primary.pickup.levels' => ['default' => [], 'source' => 'Q13 (désactivé par défaut)', 'label' => 'Niveaux soumis à la sortie sécurisée'],
        'primary.services.kinds' => ['default' => [
            'canteen' => ['fr' => 'Cantine', 'en' => 'Canteen'],
            'daycare' => ['fr' => 'Garderie', 'en' => 'Day care'],
            'transport' => ['fr' => 'Transport', 'en' => 'Transport'],
        ], 'source' => 'Q12', 'label' => 'Services proposés aux familles'],
        'primary.closure.require_councils' => ['default' => true, 'source' => 'Q10, EX-PRI-46', 'label' => 'Clôture bloquée sans conseils validés'],
        'primary.closure.require_report_cards' => ['default' => true, 'source' => 'Q10, EX-PRI-46', 'label' => 'Clôture bloquée sans bulletins émis'],
        'primary.tutelage.census_reference' => ['default' => '01-01', 'source' => 'Q16 (date de référence de l\'âge, MM-JJ)', 'label' => 'Date de référence de l\'âge au recensement'],
    ];

    public function __construct(private readonly SettingsReader $settings)
    {
    }

    public function raw(string $tenantId, string $establishmentId, string $key): mixed
    {
        return $this->settings->value($tenantId, $establishmentId, $key, self::DEFAULTS[$key]['default'] ?? null);
    }

    public function bool(string $tenantId, string $establishmentId, string $key): bool
    {
        $value = $this->raw($tenantId, $establishmentId, $key);

        return is_bool($value) ? $value : (bool) (self::DEFAULTS[$key]['default'] ?? false);
    }

    public function int(string $tenantId, string $establishmentId, string $key): int
    {
        $value = $this->raw($tenantId, $establishmentId, $key);
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        $default = self::DEFAULTS[$key]['default'] ?? 0;

        return is_int($default) ? $default : 0;
    }

    public function string(string $tenantId, string $establishmentId, string $key): ?string
    {
        $value = $this->raw($tenantId, $establishmentId, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Table « code => {fr, en, ...} » ; une valeur mal formée retombe sur le défaut.
     *
     * @return array<string, array<string, mixed>>
     */
    public function labels(string $tenantId, string $establishmentId, string $key): array
    {
        return self::table($this->raw($tenantId, $establishmentId, $key)) ?? self::table(self::DEFAULTS[$key]['default'] ?? []) ?? [];
    }

    /** @return array<string, array<string, mixed>>|null */
    private static function table(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $code => $labels) {
            if (!is_string($code) || preg_match(ScaleProvider::CODE, $code) !== 1 || !is_array($labels) || !is_string($labels['fr'] ?? null)) {
                return null;
            }
            $entry = [];
            foreach ($labels as $name => $text) {
                $entry[(string) $name] = $text;
            }
            $out[$code] = $entry + ['en' => $labels['fr']];
        }

        return $out;
    }

    /** @return list<string> liste de chaînes (identifiants, codes) ; défaut si mal formée */
    public function strings(string $tenantId, string $establishmentId, string $key): array
    {
        $value = $this->raw($tenantId, $establishmentId, $key);
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    /** Libellé localisé d'un code d'une table de libellés (le code lui-même s'il est inconnu). */
    public function label(string $tenantId, string $establishmentId, string $key, string $code, string $locale = 'fr'): string
    {
        $labels = $this->labels($tenantId, $establishmentId, $key)[$code] ?? null;
        if ($labels === null) {
            return $code;
        }
        $text = $labels[$locale] ?? $labels['fr'];

        return is_string($text) ? $text : $code;
    }

    /**
     * Toutes les clés, valeur en vigueur et valeur de départ (écran « paramètres à confirmer »).
     *
     * @return list<array{key: string, label: string, source: string, default: mixed, current: mixed, customised: bool, to_confirm: bool}>
     */
    public function describe(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $definition) {
            $current = $this->settings->value($tenantId, $establishmentId, $key, $definition['default']);
            $out[] = [
                'key' => $key, 'label' => $definition['label'], 'source' => $definition['source'], 'default' => $definition['default'],
                'current' => $current, 'customised' => $current !== $definition['default'], 'to_confirm' => true,
            ];
        }

        return $out;
    }
}
