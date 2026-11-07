<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Section « secondary » des portails du socle (/portal/learner et /portal/family, clé `portals.sections.secondary`) : pour chaque
 * inscription en cours dans un établissement secondaire (profil valide) d'un client qui a ouvert l'application, la fiche de
 * StudentCards (classe, matricule enregistré ou non, documents du secondaire, solde). Rien pour une personne hors du secondaire.
 * Les factures payables ne sont pas exposées ici : le paiement passe par /secondary/portal/family (droit secondary.family.pay).
 */
final class SecondaryPortalSection implements PortalSection
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly EstablishmentProfiles $profiles,
        private readonly StudentCards $cards,
        private readonly ApplicationAccess $applications,
    ) {
    }

    public function key(): string
    {
        return 'secondary';
    }

    public function portals(): array
    {
        return [self::LEARNER, self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        $t = $context->tenantId;
        if (!$this->applications->allowsReads($t, 'secondary')) {
            return null;
        }
        $person = $this->people->find($t, $context->subjectPersonId);
        if ($person === null || $person->tenantId !== $t) {
            return null;
        }
        $schools = [];
        foreach ($this->enrollments->forPerson($t, $person->id) as $enrollment) {
            if ($enrollment->tenantId !== $t || !in_array($enrollment->status, StudentCards::LIVE, true) || isset($schools[$enrollment->establishmentId])) {
                continue;
            }
            try {
                $profile = $this->profiles->forEstablishment($t, $enrollment->establishmentId);
            } catch (HttpException) {
                continue;
            }
            $schools[$enrollment->establishmentId] = ['establishment_id' => $enrollment->establishmentId]
                + $this->cards->build($t, $profile, $person, $enrollment, $context->full, false);
        }

        return $schools === [] ? null : ['establishments' => array_values($schools)];
    }
}
