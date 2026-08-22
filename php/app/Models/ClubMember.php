<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adhesion d'un eleve a un club.
 */
class ClubMember extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'club_id', 'student_id', 'joined_at'];

    protected $casts = ['joined_at' => 'datetime'];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
