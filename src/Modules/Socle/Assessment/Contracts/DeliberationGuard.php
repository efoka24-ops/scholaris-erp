<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Garde de délibération enfichable (S8) : préconditions d'ouverture, de clôture, de signature, de publication et de
 * réouverture, et action consécutive (alerte...). Enregistrée dans le conteneur sous `assessment.deliberation_guards.<module>`
 * (liste de classes) ; toutes les gardes sont consultées, le premier refus arrête l'opération.
 */
interface DeliberationGuard
{
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const SIGN = 'sign';
    public const PUBLISH = 'publish';
    public const REOPEN = 'reopen';

    /** @return list<GuardRefusal> vide si l'opération est permise */
    public function refusals(DeliberationContext $context): array;

    /** Appelée après une opération réussie (ouverture, clôture, signature, publication, réouverture). */
    public function after(DeliberationContext $context): void;
}
