<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Modules\Socle\Billing\Contracts\FamilyPaymentReferences;

final class PdoFamilyPaymentReferences implements FamilyPaymentReferences
{
    public function __construct(private readonly PaymentRepository $payments)
    {
    }

    public function referenceOf(string $tenantId, string $guarantorId): ?string
    {
        $row = $this->payments->referenceOfGuarantor($tenantId, $guarantorId);

        return $row === null ? null : (string) $row['reference'];
    }
}
