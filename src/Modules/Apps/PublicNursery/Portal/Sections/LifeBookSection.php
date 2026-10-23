<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Portal\Sections;

use Scholaris\Modules\Apps\PublicNursery\Development\Application\LifeBookService;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

final class LifeBookSection implements PortalSection
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly LifeBookService $lifeBook,
    ) {
    }

    public function key(): string
    {
        return 'life_book';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        if (!$context->full) {
            return null;
        }

        $items = [];
        $seen = [];
        foreach ($this->enrollments->forPerson($context->tenantId, $context->subjectPersonId) as $enrollment) {
            if ($enrollment->tenantId !== $context->tenantId || !in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true) || $enrollment->structureId === null) {
                continue;
            }
            $key = $enrollment->establishmentId . '|' . $enrollment->structureId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($this->lifeBook->all($context->tenantId, $enrollment->establishmentId, $enrollment->structureId) as $entry) {
                $items[] = $entry;
            }
        }

        if ($items === []) {
            return null;
        }

        usort($items, static fn (array $a, array $b): int => [$b['activity_on'], $b['published_at'] ?? '', $b['id']] <=> [$a['activity_on'], $a['published_at'] ?? '', $a['id']]);

        return [
            'available' => true,
            'items' => array_slice($items, 0, 12),
        ];
    }
}
