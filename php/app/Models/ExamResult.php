<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note obtenue a une epreuve d'examen officiel.
 */
class ExamResult extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'registration_id', 'subject', 'coefficient', 'mark', 'is_absent',
    ];

    protected $casts = [
        'coefficient' => 'decimal:2',
        'mark' => 'decimal:2',
        'is_absent' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(ExamRegistration::class, 'registration_id');
    }
}
