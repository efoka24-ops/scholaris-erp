<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reduction ou bourse. invoice_id renseigne applique la reduction a cette
 * facture ; student_id seul enregistre une bourse generale sans impact immediat.
 * Au moins l'un des deux est requis (invariant valide cote service).
 */
class Discount extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'invoice_id', 'type', 'value', 'reason', 'approved_by',
    ];

    protected $casts = ['value' => 'float'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Montant en devise retranche d'une base donnee (total de la facture).
     */
    public function amountOn(float $base): float
    {
        return $this->type === 'PERCENTAGE'
            ? round($base * $this->value / 100, 2)
            : min($this->value, $base);
    }
}
