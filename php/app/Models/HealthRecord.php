<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dossier medical d'un eleve (un seul par eleve).
 * Donnees sensibles : l'acces est restreint a l'infirmerie et a la direction.
 */
class HealthRecord extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'student_id', 'blood_type', 'allergies', 'chronic_diseases',
        'medications', 'vaccinations', 'emergency_contact', 'notes',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
