<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Matiere du primaire ou du secondaire (coefficient + heures hebdomadaires).
 * level_ids liste les niveaux ou la matiere est enseignee, stocke en JSON
 * plutot qu'en table de jointure : aucune contrainte referentielle requise ici.
 */
class Subject extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'coefficient', 'weekly_hours', 'category',
        'is_eliminatory', 'eliminatory_threshold', 'level_ids',
    ];

    protected $casts = [
        'coefficient' => 'decimal:2',
        'eliminatory_threshold' => 'decimal:2',
        'is_eliminatory' => 'boolean',
        'level_ids' => 'array',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /**
     * Vrai si la note fournie declenche la clause eliminatoire de la matiere.
     */
    public function isEliminatedBy(?float $mark): bool
    {
        if (! $this->is_eliminatory || $mark === null) {
            return false;
        }

        $threshold = (float) $this->eliminatory_threshold;

        return $threshold > 0 && $mark < $threshold;
    }
}
