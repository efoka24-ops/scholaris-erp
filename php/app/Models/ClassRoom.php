<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Classe pedagogique (ex: 6e A), rattachee a un niveau, avec son professeur
 * principal et sa salle attitree.
 */
class ClassRoom extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'classrooms';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'capacity', 'level_id',
        'main_teacher_id', 'room_id', 'section',
    ];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function mainTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_teacher_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'classroom_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'classroom_id');
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class, 'classroom_id');
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class, 'classroom_id');
    }

    /**
     * Effectif inscrit sur l'annee academique donnee (inscriptions actives).
     */
    public function activeHeadcount(?string $academicYearId = null): int
    {
        return $this->enrollments()
            ->where('status', 'ACTIVE')
            ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId))
            ->count();
    }
}
