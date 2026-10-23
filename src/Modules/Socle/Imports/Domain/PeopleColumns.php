<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Domain;

/**
 * Colonnes du fichier d'import des acteurs : clé interne => en-tête du modèle. Les en-têtes lus sont normalisés (minuscules, sans
 * accents, non alphanumériques en « _ ») puis rapprochés des synonymes ci-dessous ; l'ordre des colonnes est libre.
 */
final class PeopleColumns
{
    public const MAX_BYTES = 2097152;
    public const MAX_LINES = 1000;

    /** @var array<string, string> */
    public const HEADERS = [
        'last_name' => 'nom',
        'first_name' => 'prénoms',
        'email' => 'e-mail',
        'phone' => 'téléphone',
        'locale' => 'langue',
        'role' => 'rôle',
        'scope' => 'périmètre',
        'scope_code' => 'code du périmètre',
        'starts_on' => 'date de début',
        'ends_on' => 'date de fin',
        'note' => 'note',
    ];

    /** Colonnes qui doivent exister dans le fichier (les valeurs vides sont signalées ligne par ligne). */
    public const REQUIRED = ['last_name', 'first_name', 'role'];

    /** @var array<string, list<string>> synonymes normalisés */
    private const ALIASES = [
        'last_name' => ['nom', 'noms', 'nom_de_famille', 'last_name', 'surname'],
        'first_name' => ['prenoms', 'prenom', 'prenom_s', 'first_name'],
        'email' => ['e_mail', 'email', 'courriel', 'mail', 'adresse_e_mail'],
        'phone' => ['telephone', 'tel', 'phone', 'mobile', 'numero_de_telephone'],
        'locale' => ['langue', 'locale', 'language'],
        'role' => ['role', 'roles'],
        'scope' => ['perimetre', 'scope', 'portee'],
        'scope_code' => ['code_du_perimetre', 'code_perimetre', 'code_de_perimetre', 'scope_code', 'identifiant_du_perimetre', 'code'],
        'starts_on' => ['date_de_debut', 'date_debut', 'debut', 'starts_on'],
        'ends_on' => ['date_de_fin', 'date_fin', 'fin', 'ends_on'],
        'note' => ['note', 'notes', 'remarque', 'commentaire'],
    ];

    /** Rôles à durée limitée : date de fin obligatoire, 90 jours au plus (R21 jury, R22 tutelle, R23 auditeur). */
    public const TEMPORARY_ROLES = ['higher_jury', 'higher_tutelle', 'higher_auditeur'];
    public const TEMPORARY_MAX_DAYS = 90;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function headers(): array
    {
        return array_values(self::HEADERS);
    }

    /** Clé interne d'un en-tête lu dans le fichier, null s'il est inconnu. */
    public static function keyOf(string $header): ?string
    {
        $normalized = self::normalize($header);
        foreach (self::ALIASES as $key => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $key;
            }
        }
        // « date de fin (obligatoire pour les rôles temporaires) » du modèle : on compare au début de l'en-tête.
        foreach (['date_de_fin' => 'ends_on', 'date_de_debut' => 'starts_on'] as $prefix => $key) {
            if (str_starts_with($normalized, $prefix . '_')) {
                return $key;
            }
        }

        return null;
    }

    /** Minuscules, sans accents, tout ce qui n'est pas alphanumérique devient « _ ». */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', '’' => "'",
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
    }

    /**
     * Lignes d'exemple du modèle : fictives, signalées « EXEMPLE » dans la note (une ligne d'exemple oubliée est refusée à l'analyse).
     *
     * @return list<list<string>>
     */
    public static function samples(): array
    {
        return [
            ['Ngono', 'Marie Claire', 'marie.ngono@exemple.test', '691 00 00 01', 'fr', 'enseignant', 'établissement', '', '2026-11-02', '', 'EXEMPLE : supprimez cette ligne'],
            ['Abena', 'Paul', '', '691000002', 'en', 'comptable', 'unité', '<identifiant de l\'unité>', '2026-11-02', '2027-06-30', 'EXEMPLE : supprimez cette ligne'],
        ];
    }
}
