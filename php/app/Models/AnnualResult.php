<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bilan annuel d'un eleve : moyenne, rang, mention, decision de passage, et
 * pour le LMD les credits valides et le GPA.
 */
class AnnualResult extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'classroom_id', 'academic_year_id',
        'annual_average', 'rank', 'mention', 'decision', 'credits_validated', 'gpa',
    ];

    protected $casts = [
        'annual_average' => 'decimal:2',
        'gpa' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
