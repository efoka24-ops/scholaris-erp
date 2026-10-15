<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Périmètre dans lequel une permission s'exerce (S15) : tout l'établissement, une unité interne (école, département), une
 * structure pédagogique (filière) ou une classe/un groupe. Un rôle de niveau client donne le périmètre `establishment`.
 */
final class Scope
{
    public const ESTABLISHMENT = 'establishment';
    public const ORG_UNIT = 'org_unit';
    public const STRUCTURE = 'structure';
    public const CLASS_GROUP = 'class';

    public function __construct(
        /** establishment, org_unit, structure ou class */
        public readonly string $type,
        /** identifiant de l'établissement, de l'unité, de la structure ou de la classe */
        public readonly string $id,
        /** rôle qui donne ce périmètre */
        public readonly string $role,
    ) {
    }

    public function isWholeEstablishment(): bool
    {
        return $this->type === self::ESTABLISHMENT;
    }
}
