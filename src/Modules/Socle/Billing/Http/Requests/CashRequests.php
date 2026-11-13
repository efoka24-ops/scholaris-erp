<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Requests;

/** Ouverture, arrêté et versement de caisse (le type de requête est choisi par le contrôleur). */
final class CashRequests extends ValidatedRequest
{
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const DEPOSIT = 'deposit';

    public function __construct(private readonly string $kind)
    {
    }

    protected function fields(): array
    {
        return match ($this->kind) {
            self::OPEN => ['float_xaf' => Field::int(0, 100000000)->required(), 'register_no' => Field::int(1, 99)],
            self::CLOSE => ['counted_xaf' => Field::int(0, 1000000000)->required(), 'note' => Field::string(255)],
            default => ['amount_xaf' => Field::int(1, 1000000000)->required(), 'reference' => Field::string(80)->required()],
        };
    }
}
