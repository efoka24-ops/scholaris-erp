<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;

/**
 * Profil de rendu par défaut d'un document (C-118, NF-S2) : les bulletins (catégorie `bulletin`) sont rendus en profil léger
 * tant que `documents.batch.light_profile` est vrai (défaut) ; les autres documents restent en profil standard.
 */
final class PdfProfiles
{
    public function __construct(private readonly Config $config)
    {
    }

    public function forCategory(string $category): string
    {
        $light = filter_var($this->config->get('documents.batch.light_profile', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        return $category === 'bulletin' && $light ? LightPdfProfile::LIGHT : LightPdfProfile::STANDARD;
    }
}
