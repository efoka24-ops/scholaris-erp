<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Inscription d'un eleve dans une classe pour une annee academique.
 * C'est elle qui porte le regime (externe / demi-pension / internat) et qui
 * declenche la generation de la facture de scolarite.
 */
class Enrollment extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'classroom_id', 'academic_year_id',
        'enrollment_date', 'type', 'status', 'regime', 'is_repeater',
        'previous_school', 'previous_average', 'documents',
    ];

    protected $casts = [
        'enrollment_date' => 'datetime',
        'is_repeater' => 'boolean',
        'previous_average' => 'float',
        'documents' => 'array',
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
