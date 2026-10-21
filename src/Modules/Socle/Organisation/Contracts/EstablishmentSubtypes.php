<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Types d'établissement du secondaire (liste fermée, codes stables) : l'application « secondaire » est unique, le type
 * choisi par la personne sert à la personnaliser. Obligatoire à la demande d'accès pour le secondaire, absent ailleurs.
 */
final class EstablishmentSubtypes
{
    /** Niveau concerné par la liste. */
    public const SECONDARY = 'secondary';
    public const CODES = ['leg', 'lycee_technique', 'ces', 'cetic', 'college_prive', 'lycee_prive', 'autre'];

    private function __construct()
    {
    }

    /**
     * Codes autorisés pour un type d'établissement ([] : aucun sous-type).
     *
     * @return list<string>
     */
    public static function allowedFor(string $type): array
    {
        return $type === self::SECONDARY ? self::CODES : [];
    }
}
