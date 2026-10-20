<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

use InvalidArgumentException;

/**
 * Périmètre dans lequel une permission s'exerce (S15) : tout l'établissement, un territoire, une unité interne (école,
 * département), une structure pédagogique (filière) ou une classe/un groupe. Un périmètre territorial reste subordonné à son
 * tenant et à l'établissement dans lequel il est résolu.
 */
final class Scope
{
    public const ESTABLISHMENT = 'establishment';
    public const TERRITORY = 'territory';
    public const ORG_UNIT = 'org_unit';
    public const STRUCTURE = 'structure';
    public const CLASS_GROUP = 'class';

    public function __construct(
        /** establishment, territory, org_unit, structure ou class */
        public readonly string $type,
        /** identifiant de l'établissement, de l'unité, de la structure ou de la classe */
        public readonly string $id,
        /** rôle qui donne ce périmètre */
        public readonly string $role,
        /** tenant ayant validé le périmètre ; obligatoire pour un scope territorial */
        public readonly ?string $tenantId = null,
        /** établissement qui borne le périmètre territorial ; obligatoire pour un scope territorial */
        public readonly ?string $establishmentId = null,
    ) {
        if ($type === self::TERRITORY && (trim($id) === '' || trim((string) $tenantId) === '' || trim((string) $establishmentId) === '')) {
            throw new InvalidArgumentException('Un périmètre territorial doit rester borné à un tenant et un établissement.');
        }
    }

    public static function territory(string $territoryId, string $role, string $tenantId, string $establishmentId): self
    {
        return new self(self::TERRITORY, $territoryId, $role, $tenantId, $establishmentId);
    }

    public function isTerritory(): bool
    {
        return $this->type === self::TERRITORY;
    }

    public function isWholeEstablishment(): bool
    {
        return $this->type === self::ESTABLISHMENT;
    }
}
