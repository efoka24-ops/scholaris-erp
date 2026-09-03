<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tranche de l'echeancier d'une grille tarifaire.
 *
 * Purement indicative : le paiement se fait toujours contre le solde global de
 * la facture, jamais tranche par tranche.
 */
class FeeInstallment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'fee_structure_id', 'label', 'amount', 'due_date', 'order'];

    protected $casts = [
        'amount' => 'float',
        'due_date' => 'date',
    ];

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }
}
