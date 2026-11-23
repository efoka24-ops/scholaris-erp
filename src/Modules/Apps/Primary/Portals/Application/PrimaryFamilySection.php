<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Portals\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\ClassLife\Application\FamilyService;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Section « primary » du portail famille (EX-PRI-39) : pour chaque enfant inscrit dans une classe d'un niveau PRIMAIRE d'un client qui a
 * ouvert l'application, le cahier de liaison publié (cinq dernières entrées, accusés de lecture du parent) et les compétences PUBLIÉES
 * (feuille verrouillée puis publiée par la direction). Rien n'est rendu si le consentement manque (accès restreint) ou pour un enfant
 * hors du primaire : la section est omise.
 */
final class PrimaryFamilySection implements PortalSection
{
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];
    private const RECENT = 5;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
        private readonly FamilyService $family,
        private readonly ApplicationAccess $applications,
    ) {
    }

    public function key(): string
    {
        return 'primary';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        if (!$context->full || $context->portal !== self::FAMILY || !$this->applications->allowsReads($context->tenantId, 'primary')) {
            return null;
        }
        foreach ($this->enrollments->forPerson($context->tenantId, $context->subjectPersonId) as $enrollment) {
            if (!in_array($enrollment->status, self::LIVE, true) || $enrollment->structureId === null) {
                continue;
            }
            $levelId = $this->tree->levelOf($context->tenantId, $enrollment->establishmentId, $enrollment->structureId);
            if ($levelId === null || $this->levels->profile($context->tenantId, $enrollment->establishmentId, $levelId) === null) {
                continue;
            }
            $class = $this->tree->structure($context->tenantId, $enrollment->establishmentId, $enrollment->structureId);
            $journal = $this->family->journalOf($context->tenantId, $enrollment->establishmentId, $enrollment->structureId, $context->viewerPersonId, self::RECENT);

            return [
                'establishment_id' => $enrollment->establishmentId,
                'class' => $class === null ? null : ['id' => $class->id, 'name' => $class->name],
                'journal' => $journal,
                'unseen' => count(array_filter($journal, static fn (array $entry): bool => $entry['seen_at'] === null)),
                'competencies' => $this->family->competenciesOf($context->tenantId, $enrollment->establishmentId, $context->subjectPersonId, $enrollment->structureId, null)['periods'],
            ];
        }

        return null;
    }
}
