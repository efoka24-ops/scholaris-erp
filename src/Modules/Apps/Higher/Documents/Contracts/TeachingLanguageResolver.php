<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Contracts;

/**
 * Langue d'enseignement d'une filière (RG-DOC-8 : langue par défaut des documents). À fournir par le module qui gère les
 * filières, sous la clé de liste `higher.documents.language_resolvers.<module>` => [MaClasse::class] ; le premier résultat non nul
 * l'emporte, sinon la langue par défaut de l'école (profil documentaire), sinon le français.
 */
interface TeachingLanguageResolver
{
    /** @return 'fr'|'en'|null */
    public function languageOf(string $tenantId, string $establishmentId, string $programId): ?string;
}
