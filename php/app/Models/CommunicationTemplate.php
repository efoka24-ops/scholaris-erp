<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modele de message bilingue (fr/en) pour un canal donne.
 */
class CommunicationTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'channel',
        'subject_fr', 'subject_en', 'body_fr', 'body_en',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'template_id');
    }

    /**
     * Corps du message dans la langue demandee, avec repli sur le francais.
     */
    public function body(string $locale = 'fr'): string
    {
        return ($locale === 'en' ? $this->body_en : null) ?? $this->body_fr;
    }

    public function subject(string $locale = 'fr'): ?string
    {
        return ($locale === 'en' ? $this->subject_en : null) ?? $this->subject_fr;
    }
}
