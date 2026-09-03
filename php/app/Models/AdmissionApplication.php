<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Candidature d'admission (sur concours, sur dossier ou directe), anterieure a
 * la creation du dossier eleve.
 */
class AdmissionApplication extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'applicant_name', 'applicant_info', 'type',
        'score', 'rank', 'status', 'academic_year_id',
    ];

    protected $casts = [
        'applicant_info' => 'array',
        'score' => 'float',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
