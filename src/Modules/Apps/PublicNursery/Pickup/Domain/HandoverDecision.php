<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Domain;

final class HandoverDecision
{
    public const HANDED_OVER = 'handed_over';
    public const REFUSED = 'refused';

    /** @param array<string, mixed> $event */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly array $event,
    ) {
    }

    /** @param array<string, mixed> $event */
    public static function handedOver(array $event): self
    {
        return new self(self::HANDED_OVER, 'authorized', $event);
    }

    /** @param array<string, mixed> $event */
    public static function refused(string $reason, array $event): self
    {
        return new self(self::REFUSED, $reason, $event);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'event' => $this->event,
        ];
    }
}
