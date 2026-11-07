<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

enum PaymentChannel: string
{
    case Cash = 'cash';
    case OrangeMoney = 'orange_money';
    case MtnMomo = 'mtn_momo';
    case Transfer = 'transfer';
    case Card = 'card';

    public function isMobile(): bool
    {
        return $this === self::OrangeMoney || $this === self::MtnMomo;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
