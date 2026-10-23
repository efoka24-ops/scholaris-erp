<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/** Indicateur calculé ou déclaré indisponible : jamais de chiffre inventé (available=false => value=null). */
final class Indicator
{
    /** @param array<string, scalar|null> $detail */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $available,
        public readonly int|float|null $value,
        public readonly string $unit,
        public readonly ?string $reason,
        public readonly array $detail,
    ) {
    }

    /** @param array<string, scalar|null> $detail */
    public static function of(string $key, string $label, int|float $value, string $unit = 'count', array $detail = []): self
    {
        return new self($key, $label, true, $value, $unit, null, $detail);
    }

    public static function unavailable(string $key, string $label, string $reason, string $unit = 'count'): self
    {
        return new self($key, $label, false, null, $unit, $reason, []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['key' => $this->key, 'label' => $this->label, 'available' => $this->available, 'value' => $this->value, 'unit' => $this->unit];
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        if ($this->detail !== []) {
            $out['detail'] = $this->detail;
        }

        return $out;
    }
}
