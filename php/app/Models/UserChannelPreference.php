<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canal de contact prefere d'un utilisateur, avec canal de repli si l'envoi
 * echoue. Table separee de users pour ne pas alourdir le modele de compte.
 */
class UserChannelPreference extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'preferred_channel', 'fallback_channel'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
