<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Contrat SORTANT local : personne derrière un compte utilisateur (un enseignant saisit ses notes, un parent lit
 * celles de ses enfants). L'implémentation par défaut passe par le contrat Identity\Contracts\UserPersonLink.
 */
interface ActorDirectory
{
    public function personIdOf(string $tenantId, string $userId): ?string;
}
