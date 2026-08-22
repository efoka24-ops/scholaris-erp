<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Etablissement scolaire. Racine de l'isolation multi-tenant : toutes les autres
 * entites metier portent un tenant_id filtre par le trait BelongsToTenant.
 */
class Tenant extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'status', 'address', 'phone', 'email',
        'logo_url', 'public_enrollment_enabled', 'config_json',
    ];

    protected $casts = [
        'public_enrollment_enabled' => 'boolean',
        'config_json' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Annee academique active, ou null si aucune ouverte.
     */
    public function currentAcademicYear(): ?AcademicYear
    {
        return $this->academicYears()->where('status', 'ACTIVE')->latest('start_date')->first();
    }
}
