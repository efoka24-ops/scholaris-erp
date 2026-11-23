<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

/**
 * Périmètre d'une personne pour une permission : tout l'établissement, ou une liste de structures (classes, et niveaux ou sections
 * développés en leurs classes par ScopeResolver). `classIds` contient aussi les identifiants de niveaux d'un périmètre `structure`.
 */
final class Perimeter
{
    /** @param list<string> $classIds */
    public function __construct(public readonly string $tenantId, public readonly string $establishmentId, public readonly bool $wholeEstablishment, public readonly array $classIds = [])
    {
    }

    public function coversClass(?string $classId): bool
    {
        return $classId !== null && ($this->wholeEstablishment || in_array($classId, $this->classIds, true));
    }

    public function isEmpty(): bool
    {
        return !$this->wholeEstablishment && $this->classIds === [];
    }

    /** Réunion de deux périmètres du même établissement (plusieurs permissions pour une même opération). */
    public function union(self $other): self
    {
        return new self(
            $this->tenantId,
            $this->establishmentId,
            $this->wholeEstablishment || $other->wholeEstablishment,
            array_values(array_unique([...$this->classIds, ...$other->classIds])),
        );
    }
}
