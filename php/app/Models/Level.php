<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Niveau (ex: 6e, Terminale, Licence 1). Porte un cycle_id direct en plus du
 * program_id optionnel, pour qu'un niveau sans filiere (college) reste rattache
 * a son cycle.
 */
class Level extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'code', 'name', 'order', 'cycle_id', 'program_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }
}
