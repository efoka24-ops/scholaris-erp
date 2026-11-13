<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\TeachingLanguageResolver;

/** Langue d'enseignement de test : la filière DR (droit) est enseignée en anglais, les autres sans avis. */
final class EnglishProgramLanguage implements TeachingLanguageResolver
{
    public function languageOf(string $tenantId, string $establishmentId, string $programId): ?string
    {
        return $programId === 'DR' ? 'en' : null;
    }
}
