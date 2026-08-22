<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Compte de connexion, toujours rattache a un etablissement.
 *
 * Le mot de passe est stocke dans password_hash (nom de colonne herite du
 * schema Prisma), do la redefinition de getAuthPassword().
 */
class User extends Authenticatable
{
    use BelongsToTenant;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'email', 'password_hash', 'first_name', 'last_name',
        'phone', 'status', 'avatar_url',
    ];

    protected $hidden = ['password_hash', 'mfa_secret', 'remember_token'];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'last_login' => 'datetime',
        'locked_until' => 'datetime',
        'password_hash' => 'hashed',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function parentProfile(): HasOne
    {
        return $this->hasOne(GuardianParent::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Vrai si l'utilisateur porte le role indique (nom exact, ex: SUPER_ADMIN).
     */
    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    /**
     * Vrai si l'un des roles accorde la permission resource:action, ou la
     * permission "manage" sur la meme ressource.
     */
    public function hasPermission(string $resource, string $action): bool
    {
        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->contains(fn (Permission $permission) => $permission->resource === $resource
                && in_array($permission->action, [$action, 'manage'], true));
    }

    /**
     * Le compte est verrouille apres trop d'echecs de connexion.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
