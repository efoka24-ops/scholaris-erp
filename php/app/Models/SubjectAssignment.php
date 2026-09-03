<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Affectation d'un enseignant a une matiere OU a un element constitutif, pour
 * une classe et une annee academique donnees. Exactement l'un de subject_id et
 * course_element_id est renseigne (invariant valide cote service, MySQL
 * n'appliquant pas l'unicite sur colonne NULL).
 */
class SubjectAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'subject_id', 'course_element_id', 'teacher_id',
        'classroom_id', 'academic_year_id',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function courseElement(): BelongsTo
    {
        return $this->belongsTo(CourseElement::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Libelle de la discipline enseignee, quelle que soit la branche du schema.
     */
    public function getDisciplineNameAttribute(): ?string
    {
        return $this->subject?->name ?? $this->courseElement?->name;
    }
}
