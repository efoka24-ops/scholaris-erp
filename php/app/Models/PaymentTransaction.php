<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transaction Mobile Money initiee via une passerelle (CAMOO, apisungku).
 *
 * Volontairement denormalisee (student_id et invoice_id sans contrainte) pour
 * rester isolee du reste du schema : la passerelle peut evoluer sans migration
 * sur les tables metier.
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'gateway_id', 'external_reference', 'amount', 'currency',
        'phone_number', 'network', 'status', 'fees', 'net_amount',
        'student_id', 'invoice_id', 'raw_response', 'notified_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'raw_response' => 'array',
        'notified_at' => 'datetime',
    ];

    public function isSettled(): bool
    {
        return in_array($this->status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true);
    }
}
