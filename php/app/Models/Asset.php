<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bien du patrimoine (mobilier, equipement, batiment, vehicule).
 */
class Asset extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'category', 'acquisition_date',
        'acquisition_value', 'status', 'location',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'acquisition_value' => 'float',
    ];

    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }
}
