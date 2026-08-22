<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Moyenne calculee d'un eleve dans une matiere ou un EC sur une periode.
 *
 * classroom_id est denormalise depuis l'inscription active au moment du calcul,
 * pour classer les eleves par classe sans re-parcourir les inscriptions.
 */
class GradeCalculation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'period_id', 'subject_id', 'course_element_id',
        'classroom_id', 'calculated_average', 'coefficient', 'weighted_total', 'rank',
    ];

    protected $casts = [
        'calculated_average' => 'decimal:2',
        'coefficient' => 'decimal:2',
        'weighted_total' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function courseElement(): BelongsTo
    {
        return $this->belongsTo(CourseElement::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }
}
