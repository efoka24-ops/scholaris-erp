<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Domain;

/**
 * Rôle d'une matière dans un niveau (RG-ORG-4) : officielle, de projet (club, option : HORS moyenne officielle), pratique
 * (technique, Q8) ou de stage. Origine d'un coefficient : exemple, officiel (source renseignée), propre à l'établissement.
 */
final class SubjectRoles
{
    public const OFFICIAL = 'official';
    public const PROJECT = 'project';
    public const PRACTICAL = 'practical';
    public const INTERNSHIP = 'internship';
    public const ALL = [self::OFFICIAL, self::PROJECT, self::PRACTICAL, self::INTERNSHIP];

    public const ORIGINS = ['example', 'official', 'custom'];

    private function __construct()
    {
    }

    public static function countsInAverage(string $role): bool
    {
        return $role !== self::PROJECT;
    }
}
