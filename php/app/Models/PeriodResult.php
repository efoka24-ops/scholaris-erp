<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bilan d'un eleve sur une periode : moyenne generale, rang, mention,
 * deliberation. is_published controle la visibilite pour parents et eleves.
 */
class PeriodResult extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'period_id', 'classroom_id', 'general_average',
        'rank', 'total_students', 'mention', 'decision', 'observations',
        'teacher_comment', 'is_published',
    ];

    protected $casts = [
        'general_average' => 'decimal:2',
        'is_published' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }
}
