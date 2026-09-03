<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dossier eleve. user_id relie optionnellement le dossier a un compte de
 * connexion (role Eleve) pour scoper son acces a ses seules donnees.
 */
class Student extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'matricule', 'first_name', 'last_name', 'date_of_birth',
        'place_of_birth', 'gender', 'nationality', 'photo_url', 'blood_group',
        'allergies', 'handicap', 'emergency_contact', 'status', 'user_id',
    ];

    protected $casts = ['date_of_birth' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(GuardianParent::class, 'student_parents', 'student_id', 'parent_id')
            ->withPivot('relationship');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function healthRecord(): HasOne
    {
        return $this->hasOne(HealthRecord::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Inscription active de l'annee academique donnee (ou de toute annee si
     * aucune precisee), source de la classe courante de l'eleve.
     */
    public function activeEnrollment(?string $academicYearId = null): ?Enrollment
    {
        return $this->enrollments()
            ->where('status', 'ACTIVE')
            ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId))
            ->latest('enrollment_date')
            ->first();
    }
}
