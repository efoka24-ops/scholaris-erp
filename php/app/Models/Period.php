<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sequence, trimestre ou semestre. grading_status pilote la saisie des notes
 * (CLOSED -> OPEN -> LOCKED).
 *
 * Pas de tenant_id propre : le scoping passe par l'annee academique parente.
 */
class Period extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'academic_year_id', 'type', 'number', 'start_date', 'end_date', 'grading_status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function periodResults(): HasMany
    {
        return $this->hasMany(PeriodResult::class);
    }

    /**
     * La saisie de notes est autorisee uniquement quand la periode est ouverte.
     */
    public function acceptsGrades(): bool
    {
        return $this->grading_status === 'OPEN';
    }
}
