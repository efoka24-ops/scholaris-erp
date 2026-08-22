<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscription d'un eleve a un examen officiel, avec son numero de candidat et
 * son centre d'examen.
 */
class ExamRegistration extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'exam_id', 'student_id', 'registration_number', 'center_code',
        'center_name', 'series', 'status', 'fee_paid', 'average', 'mention',
        'rank', 'registered_at', 'validated_by',
    ];

    protected $casts = [
        'fee_paid' => 'boolean',
        'average' => 'decimal:2',
        'registered_at' => 'datetime',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(OfficialExam::class, 'exam_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class, 'registration_id');
    }

    /**
     * Moyenne ponderee des epreuves, les absences comptant zero.
     */
    public function computeAverage(): ?float
    {
        $results = $this->results;
        $totalCoefficient = (float) $results->sum('coefficient');

        if ($totalCoefficient <= 0) {
            return null;
        }

        $weighted = $results->sum(
            fn (ExamResult $result) => (float) ($result->is_absent ? 0 : $result->mark) * (float) $result->coefficient
        );

        return round($weighted / $totalCoefficient, 2);
    }
}
