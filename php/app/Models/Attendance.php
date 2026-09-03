<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presence ou absence d'un eleve pour une journee de classe.
 */
class Attendance extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'student_id', 'classroom_id', 'date', 'status', 'reason', 'justified_by',
    ];

    protected $casts = ['date' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }

    /**
     * Absence non justifiee : compte dans les statistiques de discipline.
     */
    public function isUnjustifiedAbsence(): bool
    {
        return $this->status === 'ABSENT' && $this->justified_by === null;
    }
}
