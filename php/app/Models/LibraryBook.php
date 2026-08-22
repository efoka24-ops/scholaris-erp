<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ouvrage du fonds documentaire. available suit le nombre d'exemplaires
 * disponibles, decremente a chaque emprunt.
 */
class LibraryBook extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'title', 'author', 'isbn', 'category', 'quantity', 'available',
    ];

    public function borrows(): HasMany
    {
        return $this->hasMany(LibraryBorrow::class, 'book_id');
    }

    public function isAvailable(): bool
    {
        return $this->available > 0;
    }
}
