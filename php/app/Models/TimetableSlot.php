<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Creneau d'emploi du temps. start_time et end_time sont des chaines HH:MM
 * (pas des datetime) : un creneau se repete chaque semaine.
 */
class TimetableSlot extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'academic_year_id', 'classroom_id', 'subject_id',
        'teacher_id', 'room_id', 'day_of_week', 'start_time', 'end_time',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Vrai si ce creneau chevauche l'autre le meme jour (detection de conflit
     * de salle ou d'enseignant).
     */
    public function overlaps(self $other): bool
    {
        return $this->day_of_week === $other->day_of_week
            && $this->start_time < $other->end_time
            && $other->start_time < $this->end_time;
    }
}
