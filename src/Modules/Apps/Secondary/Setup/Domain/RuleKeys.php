<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Domain;

/**
 * Liste FERMÉE des règles du règlement intérieur (EX-SEC-11, T822) : la valeur vient de l'établissement (versionnée avec auteur,
 * date et motif) ou, à défaut, du paramètre `secondary.<clé>`. Aucune valeur livrée (Q2) : vide = « à confirmer ». Seule la forme
 * (liste, objet, valeur simple) est contrôlée, jamais le contenu réglementaire.
 */
final class RuleKeys
{
    public const LIST = 'list';
    public const OBJECT = 'object';
    public const SCALAR = 'scalar';

    /** @var array<string, string> clé => forme attendue */
    public const ALL = [
        'discipline_scale' => self::LIST,
        'admission_age_limits' => self::OBJECT,
        'sequences_per_trimester' => self::SCALAR,
        'trimesters' => self::SCALAR,
        'distinctions' => self::LIST,
        'promotion' => self::OBJECT,
        'appeal_days' => self::SCALAR,
        'entry_test' => self::OBJECT,
    ];

    private function __construct()
    {
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::ALL);
    }

    public static function settingKey(string $key): string
    {
        return 'secondary.' . $key;
    }

    public static function fits(string $key, mixed $value): bool
    {
        return match (self::ALL[$key] ?? null) {
            self::LIST => is_array($value) && array_is_list($value),
            self::OBJECT => is_array($value) && ($value === [] || !array_is_list($value)),
            self::SCALAR => is_int($value) || is_float($value) || is_string($value) || is_bool($value),
            default => false,
        };
    }
}
