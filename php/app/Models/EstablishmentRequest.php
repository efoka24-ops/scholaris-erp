<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Demande publique de creation d'etablissement.
 *
 * Un directeur la soumet sans authentification ; le Super Admin la valide, ce
 * qui cree le tenant et le compte directeur. Volontairement non tenant-scopee :
 * aucun etablissement n'existe encore au moment du depot.
 */
class EstablishmentRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'code', 'type', 'status', 'address', 'phone', 'email',
        'director_first_name', 'director_last_name', 'director_email',
        'director_phone', 'request_status', 'rejection_reason', 'created_tenant_id',
    ];

    public function isPending(): bool
    {
        return $this->request_status === 'PENDING';
    }
}
