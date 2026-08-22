<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Compteur atomique de numeros de recu par etablissement et par annee
 * (meme pattern que MatriculeSequence).
 */
class ReceiptSequence extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'year', 'last_number'];
}
