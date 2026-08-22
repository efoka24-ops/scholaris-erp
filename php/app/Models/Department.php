<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departement (superieur) ou groupe de disciplines, avec son chef de departement.
 */
class Department extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'code', 'name', 'head_teacher_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function headTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_teacher_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function teachingUnits(): HasMany
    {
        return $this->hasMany(TeachingUnit::class);
    }
}
