<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
