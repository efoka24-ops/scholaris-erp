<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Menu de cantine pour une date et un service donnes.
 */
class CateringMenu extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'date', 'meal', 'items'];

    protected $casts = ['date' => 'date'];
}
