<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Domain;

/** Ce qu'un rapport planifié contient : un tableau de bord, ou les statistiques réglementaires de l'établissement. */
enum ReportType: string
{
    case Direction = 'direction';
    case Censeur = 'censeur';
    case Groupe = 'groupe';
    case Regulatory = 'regulatory';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Direction => 'Tableau de bord de la direction',
            self::Censeur => 'Tableau de bord du censeur',
            self::Groupe => 'Vue consolidée du groupe',
            self::Regulatory => 'Statistiques réglementaires',
        };
    }

    public function dashboard(): ?DashboardKind
    {
        return DashboardKind::tryFrom($this->value);
    }
}
