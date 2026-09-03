<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local physique (salle, laboratoire, amphi, terrain), distinct de la classe
 * pedagogique : une meme salle peut accueillir plusieurs classes.
 */
class Room extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'type', 'capacity', 'building', 'floor', 'equipment',
    ];

    protected $casts = [
        'equipment' => 'array',
        'created_at' => 'datetime',
    ];

    public function classrooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class);
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }
}
