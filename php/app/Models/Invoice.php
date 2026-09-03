<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Facture de scolarite d'une inscription, generee depuis la grille tarifaire
 * applicable au niveau de la classe.
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'enrollment_id', 'fee_structure_id',
        'academic_year_id', 'total_amount', 'paid_amount', 'balance',
        'due_date', 'status',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'balance' => 'float',
        'due_date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /**
     * Recalcule paid_amount, balance et status depuis les paiements enregistres.
     * Appele apres chaque encaissement ou application de reduction.
     */
    public function recalculate(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $balance = round($this->total_amount - $paid, 2);

        $this->paid_amount = $paid;
        $this->balance = max($balance, 0);
        $this->status = $this->resolveStatus($balance);
        $this->save();
    }

    private function resolveStatus(float $balance): string
    {
        if ($balance <= 0) {
            return 'PAID';
        }

        if ($this->due_date !== null && $this->due_date->isPast()) {
            return 'OVERDUE';
        }

        return $this->paid_amount > 0 ? 'PARTIAL' : 'PENDING';
    }
}
