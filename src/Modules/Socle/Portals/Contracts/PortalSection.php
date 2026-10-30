<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Contracts;

/**
 * Section de portail fournie par une application (S20) : parcours, UE acquises et dettes, relevés disponibles, candidature...
 * Ajoutée sous `sections.<key>` dans GET /portal/learner et, pour chaque enfant, dans GET /portal/family.
 *
 * Enregistrement : clé de liste du conteneur `portals.sections.<application>` => [MaSection::class, ...]. Une section ne lit
 * que les contrats des autres modules ; elle renvoie null pour ne rien afficher (personne non concernée, accès restreint).
 */
interface PortalSection
{
    public const LEARNER = 'learner';
    public const FAMILY = 'family';

    /** Clé de la section (minuscules, chiffres, _). */
    public function key(): string;

    /** @return list<string> portails concernés (learner, family) */
    public function portals(): array;

    /** @return array<string, mixed>|null contenu de la section, ou null pour l'omettre */
    public function build(PortalSectionContext $context): ?array;
}
