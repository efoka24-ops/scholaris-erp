<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Support;

/**
 * Périmètre d'une personne pour une permission : tout l'établissement, ou une liste de sections (classes) que ses affectations
 * couvrent. Hors périmètre : 404 en lecture (l'existence est cachée), 403 journalisé en écriture (RG-MAT-7).
 */
final class Perimeter
{
    /** @param list<string> $sectionIds */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly bool $wholeEstablishment,
        public readonly array $sectionIds = [],
    ) {
    }

    public function coversSection(?string $sectionId): bool
    {
        return $sectionId !== null && ($this->wholeEstablishment || in_array($sectionId, $this->sectionIds, true));
    }

    public function isEmpty(): bool
    {
        return !$this->wholeEstablishment && $this->sectionIds === [];
    }

    /** Réunion de deux périmètres du même établissement (plusieurs permissions pour une même opération). */
    public function union(self $other): self
    {
        return new self(
            $this->tenantId,
            $this->establishmentId,
            $this->wholeEstablishment || $other->wholeEstablishment,
            array_values(array_unique([...$this->sectionIds, ...$other->sectionIds])),
        );
    }
}
