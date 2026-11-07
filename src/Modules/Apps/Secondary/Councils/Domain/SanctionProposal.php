<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

use DomainException;

/**
 * Sanction proposée par l'application (RG-PRE-2) : créée à l'état `proposed`, elle n'est appliquée qu'après la décision
 * explicite d'une personne habilitée (surveillant général ou chef d'établissement selon le niveau du palier) ; un rejet est
 * motivé. Une décision est définitive (immutable : chaque transition rend un nouvel objet).
 */
final class SanctionProposal
{
    public const PROPOSED = 'proposed';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $personId,
        public readonly string $code,
        public readonly string $label,
        public readonly string $level,
        public readonly float $hours,
        public readonly string $status,
        public readonly ?string $decidedBy = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function propose(string $personId, string $code, string $label, string $level, float $hours): self
    {
        return new self($personId, $code, $label, $level, $hours, self::PROPOSED);
    }

    public function apply(string $decidedBy): self
    {
        $this->assertOpen();

        return new self($this->personId, $this->code, $this->label, $this->level, $this->hours, self::APPLIED, $decidedBy);
    }

    public function reject(string $decidedBy, string $reason): self
    {
        $this->assertOpen();
        if (trim($reason) === '') {
            throw new DomainException('Le rejet d\'une sanction proposée est motivé.');
        }

        return new self($this->personId, $this->code, $this->label, $this->level, $this->hours, self::REJECTED, $decidedBy, $reason);
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    private function assertOpen(): void
    {
        if ($this->status !== self::PROPOSED) {
            throw new DomainException('Cette sanction a déjà fait l\'objet d\'une décision.');
        }
    }
}
