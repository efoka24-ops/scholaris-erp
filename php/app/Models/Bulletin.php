<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bulletin scolaire d'une periode.
 *
 * data est un snapshot fige des notes et moyennes au moment de la generation :
 * un recalcul ulterieur ne modifie pas un bulletin deja emis.
 * verification_code permet de controler l'authenticite d'un bulletin imprime.
 */
class Bulletin extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'student_id', 'period_id', 'classroom_id',
        'verification_code', 'status', 'pdf_url', 'data',
    ];

    protected $casts = ['data' => 'array'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'classroom_id');
    }

    public function isVisibleToFamily(): bool
    {
        return in_array($this->status, ['published', 'sent'], true);
    }
}
