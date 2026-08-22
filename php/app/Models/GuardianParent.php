<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Parent ou tuteur (table parents).
 *
 * La classe ne peut pas s'appeler Parent : "parent" est un mot reserve de PHP.
 * D'ou le nom GuardianParent, avec $table fixe explicitement.
 */
class GuardianParent extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'parents';

    protected $fillable = [
        'tenant_id', 'first_name', 'last_name', 'phone', 'whatsapp', 'email',
        'profession', 'address', 'relationship', 'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_parents', 'parent_id', 'student_id')
            ->withPivot('relationship');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
