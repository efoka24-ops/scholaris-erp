<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Element constitutif d'une UE (superieur LMD) : credits et volumes CM/TD/TP.
 * Joue le role de la matiere pour les notes du superieur.
 */
class CourseElement extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'credits', 'hours_cm', 'hours_td', 'hours_tp',
        'coefficient', 'teaching_unit_id',
    ];

    protected $casts = ['coefficient' => 'decimal:2'];

    public function teachingUnit(): BelongsTo
    {
        return $this->belongsTo(TeachingUnit::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function getTotalHoursAttribute(): int
    {
        return $this->hours_cm + $this->hours_td + $this->hours_tp;
    }
}
