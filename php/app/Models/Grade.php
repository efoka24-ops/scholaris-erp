<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Note unitaire d'un eleve. Exactement l'un de subject_id (primaire/secondaire)
 * et course_element_id (superieur LMD) est renseigne.
 *
 * is_locked verrouille la note contre toute modification par l'enseignant ;
 * seul un censeur ou un admin (permission grades:unlock) peut la rouvrir.
 */
class Grade extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'subject_id', 'course_element_id', 'period_id',
        'teacher_id', 'type', 'value', 'max_value', 'weight', 'date', 'comment',
        'is_absent', 'is_justified', 'is_locked',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'weight' => 'decimal:2',
        'date' => 'datetime',
        'is_absent' => 'boolean',
        'is_justified' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function courseElement(): BelongsTo
    {
        return $this->belongsTo(CourseElement::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Note ramenee sur 20, base commune de toutes les moyennes.
     * Null si la note est absente et sans valeur de substitution.
     */
    public function normalizedValue(): ?float
    {
        if ($this->value === null) {
            return null;
        }

        $max = (float) $this->max_value;

        return $max > 0 ? round((float) $this->value * 20 / $max, 2) : null;
    }
}
