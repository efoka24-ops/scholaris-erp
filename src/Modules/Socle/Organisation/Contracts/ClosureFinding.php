<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Résultat d'un contrôle préalable à la clôture d'une année : bloquant (blocking_count > 0), sans objet (0),
 * ou non évaluable (aucune source, ou source en erreur). Un contrôle non évaluable ne bloque pas mais est toujours listé.
 */
final class ClosureFinding
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $evaluable,
        public readonly int $blockingCount,
        public readonly ?string $detail,
    ) {
    }

    public static function of(string $key, string $label, int $blockingCount, ?string $detail = null): self
    {
        return new self($key, $label, true, max(0, $blockingCount), $detail);
    }

    public static function notEvaluable(string $key, string $label, string $reason): self
    {
        return new self($key, $label, false, 0, $reason);
    }

    public function blocks(): bool
    {
        return $this->evaluable && $this->blockingCount > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => !$this->evaluable ? 'not_evaluable' : ($this->blockingCount > 0 ? 'blocking' : 'ok'),
            'blocking_count' => $this->blockingCount,
            'detail' => $this->detail,
        ];
    }
}
