<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/** Portails famille et apprenant : une personne, un compte, tous les établissements du client. */
final class PortalService
{
    public function __construct(
        private readonly ActorDirectory $actors,
        private readonly PersonDirectory $people,
        private readonly ConsentChecker $consents,
        private readonly AdultLearnerCheck $adults,
        private readonly LearnerCardBuilder $cards,
        private readonly TimetableDirectory $timetable,
        private readonly Clock $clock,
        private readonly PortalSections $sections,
    ) {
    }

    /** @return array<string, mixed> */
    public function family(string $tenantId, string $userId): array
    {
        $guarantorId = $this->personOf($tenantId, $userId);
        $children = [];
        $total = 0;
        foreach ($this->people->learnersOf($tenantId, $guarantorId) as $child) {
            if ($child->tenantId !== $tenantId) {
                continue;
            }
            $full = $this->adults->isAdult($tenantId, $child->id) === false || $this->consents->hasConsent($tenantId, $child->id, ConsentChecker::PARENT_ACCESS);
            $card = $this->cards->build($tenantId, $child, $full);
            $sections = $this->sections->build(new PortalSectionContext($tenantId, PortalSection::FAMILY, $guarantorId, $child->id, $full));
            if ($sections !== []) {
                $card['sections'] = $sections;
            }
            $total += (int) $card['balance_xaf'];
            $children[] = $card;
        }

        return [
            'guarantor_id' => $guarantorId,
            'family_balance_xaf' => $total,
            'next_due' => ['available' => false, 'reason' => 'Aucun contrat n\'expose les échéances.'],
            'children' => $children,
        ];
    }

    /** @return array<string, mixed> */
    public function learner(string $tenantId, string $userId): array
    {
        $personId = $this->personOf($tenantId, $userId);
        $person = $this->people->find($tenantId, $personId);
        if ($person === null || $person->tenantId !== $tenantId) {
            throw HttpException::notFound('Fiche introuvable.');
        }
        $active = $this->cards->activeEnrollments($tenantId, $personId);
        $card = $this->cards->build($tenantId, $person, true, $active);

        $today = date('Y-m-d', $this->clock->now());
        $lessons = [];
        foreach (array_unique(array_map(static fn ($e): string => $e->establishmentId, $active)) as $establishmentId) {
            $structures = array_map(static fn ($e): ?string => $e->structureId, array_filter($active, static fn ($e): bool => $e->establishmentId === $establishmentId));
            foreach ($this->timetable->slotsOn($tenantId, $establishmentId, $today) as $slot) {
                if (in_array($slot->structureId, $structures, true)) {
                    $lessons[] = ['subject' => $slot->subjectLabel, 'start' => $slot->startTime, 'end' => $slot->endTime, 'establishment_id' => $establishmentId];
                }
            }
        }
        usort($lessons, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $out = $card + ['today' => $today, 'timetable_today' => $lessons];
        // Sections déclarées par les applications (S20) : clé absente si aucune.
        $sections = $this->sections->build(new PortalSectionContext($tenantId, PortalSection::LEARNER, $personId, $personId, true));

        return $sections === [] ? $out : $out + ['sections' => $sections];
    }

    private function personOf(string $tenantId, string $userId): string
    {
        $personId = $this->actors->personIdOf($tenantId, $userId);
        if ($personId === null) {
            throw HttpException::forbidden('Votre compte n\'est rattaché à aucune personne : contactez l\'établissement.');
        }

        return $personId;
    }
}
