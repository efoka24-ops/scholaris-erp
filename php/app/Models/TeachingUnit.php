<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unite d'enseignement (superieur LMD), composee d'elements constitutifs.
 */
class TeachingUnit extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'credits', 'semester', 'is_fundamental', 'department_id',
    ];

    protected $casts = ['is_fundamental' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function courseElements(): HasMany
    {
        return $this->hasMany(CourseElement::class);
    }
}
