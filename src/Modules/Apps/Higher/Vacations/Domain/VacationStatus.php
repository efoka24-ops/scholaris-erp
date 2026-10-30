<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Domain;

/**
 * Cycle d'un état mensuel de vacations : établi (draft) → visé par l'école (school_validated) → approuvé par la direction (approved)
 * → payé (paid). Un état non payé peut être rejeté ; un état établi ou rejeté peut être recalculé (paramètres, heures).
 */
final class VacationStatus
{
    public const DRAFT = 'draft';
    public const SCHOOL_VALIDATED = 'school_validated';
    public const APPROVED = 'approved';
    public const PAID = 'paid';
    public const REJECTED = 'rejected';

    public const ALL = [self::DRAFT, self::SCHOOL_VALIDATED, self::APPROVED, self::PAID, self::REJECTED];

    private function __construct()
    {
    }

    public static function recomputable(string $status): bool
    {
        return in_array($status, [self::DRAFT, self::REJECTED], true);
    }

    public static function rejectable(string $status): bool
    {
        return in_array($status, [self::DRAFT, self::SCHOOL_VALIDATED, self::APPROVED], true);
    }
}
