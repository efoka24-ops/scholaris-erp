<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission atomique, identifiee par le couple ressource + action
 * (create | read | update | delete | manage). Referentiel global, hors tenant.
 */
class Permission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['resource', 'action', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
