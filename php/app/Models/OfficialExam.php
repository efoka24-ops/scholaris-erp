<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Examen officiel (CEP, BEPC, Probatoire, BAC) ou examen maison configurable.
 * Les FK vers levels et academic_years sont scalaires (pas de contrainte) pour
 * garder le module isole du reste du schema.
 */
class OfficialExam extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'level_id', 'academic_year_id',
        'registration_start', 'registration_end', 'exam_start', 'exam_end',
        'fee_amount', 'min_age', 'max_age', 'required_sequences',
        'pass_mark', 'oral_min_mark', 'is_official', 'config_json',
    ];

    protected $casts = [
        'registration_start' => 'datetime',
        'registration_end' => 'datetime',
        'exam_start' => 'datetime',
        'exam_end' => 'datetime',
        'fee_amount' => 'decimal:2',
        'pass_mark' => 'decimal:2',
        'oral_min_mark' => 'decimal:2',
        'is_official' => 'boolean',
        'config_json' => 'array',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(ExamRegistration::class, 'exam_id');
    }

    /**
     * Vrai si la periode d'inscription est ouverte a cet instant.
     */
    public function registrationIsOpen(): bool
    {
        return now()->between($this->registration_start, $this->registration_end);
    }
}
