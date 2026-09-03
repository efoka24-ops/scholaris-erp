<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne de ramassage scolaire, desservie par un vehicule.
 */
class TransportRoute extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'name', 'vehicle_id', 'stops', 'schedule'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'vehicle_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TransportSubscription::class, 'route_id');
    }

    /**
     * Places restantes sur la ligne, d'apres la capacite du vehicule affecte.
     */
    public function remainingSeats(): ?int
    {
        if ($this->vehicle === null) {
            return null;
        }

        return max($this->vehicle->capacity - $this->subscriptions()->count(), 0);
    }
}
