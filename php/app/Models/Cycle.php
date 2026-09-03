<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cycle d'enseignement (ex: Maternelle, Primaire, 1er cycle, 2nd cycle).
 * Racine de la hierarchie pedagogique.
 */
class Cycle extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'code', 'name', 'order'];

    protected $casts = ['created_at' => 'datetime'];

    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
