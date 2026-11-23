<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

/** Lien entre un apprenant (person_id) et une autre personne (related_person_id) : « related est <kind> de person ». */
enum RelationKind: string
{
    case Parent = 'parent';
    case Guardian = 'guardian';
    case FinancialGuarantor = 'financial_guarantor';
    case AuthorizedPickup = 'authorized_pickup';
    case Emergency = 'emergency';

    /** Les parents, tuteurs et garants financiers peuvent consulter le dossier d'un mineur. */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Parent, self::Guardian, self::FinancialGuarantor => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
