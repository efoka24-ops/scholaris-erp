<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Valeurs d'une autorisation administrative (D2). Dates au format AAAA-MM-JJ, bornes incluses. */
final class AuthorizationData
{
    /** @param list<string>|null $coveredLevels identifiants des structures de niveau couvertes ; null = tous les niveaux */
    public function __construct(
        public readonly string $structureId,
        public readonly string $reference,
        public readonly string $validFrom,
        public readonly string $validTo,
        public readonly ?string $authority = null,
        public readonly ?array $coveredLevels = null,
        /** pièce numérisée (identifiant d'un document) */
        public readonly ?string $documentId = null,
    ) {
    }
}
