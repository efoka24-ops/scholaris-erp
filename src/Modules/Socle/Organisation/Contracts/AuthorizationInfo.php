<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Autorisation administrative enregistrée (lecture seule). */
final class AuthorizationInfo
{
    /** @param list<string>|null $coveredLevels null = tous les niveaux */
    public function __construct(
        public readonly string $id,
        public readonly string $establishmentId,
        public readonly string $structureId,
        public readonly string $reference,
        public readonly ?string $authority,
        public readonly string $validFrom,
        public readonly string $validTo,
        public readonly ?array $coveredLevels,
        public readonly ?string $documentId,
    ) {
    }

    /** Valide le jour donné (AAAA-MM-JJ), bornes incluses. */
    public function isValidOn(string $day): bool
    {
        return $this->validFrom <= $day && $day <= $this->validTo;
    }

    public function coversLevel(?string $levelId): bool
    {
        if ($this->coveredLevels === null || $this->coveredLevels === []) {
            return true;
        }

        return $levelId !== null && in_array($levelId, $this->coveredLevels, true);
    }
}
