<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Compteur atomique de matricules par etablissement et par annee.
 *
 * Incremente via lockForUpdate() dans la meme transaction que la creation de
 * l'eleve : deux inscriptions concurrentes ne peuvent obtenir le meme numero,
 * et la sequence ne laisse pas de trou.
 */
class MatriculeSequence extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'year', 'last_number'];
}
