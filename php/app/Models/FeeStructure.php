<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grille tarifaire d'une annee academique. level_id null = grille par defaut,
 * applicable a tous les niveaux qui n'ont pas de grille dediee.
 */
class FeeStructure extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'level_id', 'academic_year_id', 'total_amount'];

    protected $casts = ['total_amount' => 'float'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FeeInstallment::class)->orderBy('order');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Echeance la plus tardive de la grille, retenue comme date limite de la
     * facture generee. Null si la grille ne definit aucune tranche.
     */
    public function lastDueDate(): ?string
    {
        return $this->installments()->max('due_date');
    }
}
