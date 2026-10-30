<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

enum EnrollmentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Conditional = 'conditional';
    case Suspended = 'suspended';
    case Withdrawn = 'withdrawn';
    case Excluded = 'excluded';
    case Transferred = 'transferred';
    case Graduated = 'graduated';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Conditional => [self::Active, self::Withdrawn, self::Excluded],
            self::Active => [self::Suspended, self::Withdrawn, self::Excluded, self::Transferred, self::Graduated],
            self::Suspended => [self::Active, self::Withdrawn, self::Excluded],
            default => [],
        };
    }

    /** Une inscription terminée ne bloque plus une nouvelle inscription pour la même année. */
    public function isLive(): bool
    {
        return in_array($this, [self::Draft, self::Active, self::Conditional, self::Suspended], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
