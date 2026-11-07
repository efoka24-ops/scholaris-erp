<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Portal\Sections;

use Scholaris\Modules\Apps\PublicNursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

final class ProgressSection implements PortalSection
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly ProgressBookService $books,
    ) {
    }

    public function key(): string
    {
        return 'progress_book';
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

        $establishments = array_values(array_unique(array_map(
            static fn (EnrollmentInfo $e): string => $e->establishmentId,
            array_filter(
                $this->enrollments->forPerson($context->tenantId, $context->subjectPersonId),
                static fn (EnrollmentInfo $e): bool => $e->tenantId === $context->tenantId && in_array($e->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true),
            ),
        )));
        $book = $this->books->latestPublishedForLearner($context->tenantId, $context->subjectPersonId, $establishments);
        if ($book === null) {
            return null;
        }

        return [
            'available' => true,
            'book' => [
                'id' => $book['id'],
                'establishment_id' => $book['establishment_id'],
                'structure_id' => $book['structure_id'],
                'period_id' => $book['period_id'],
                'school_year' => $book['school_year'],
                'synthesis' => $book['synthesis'],
                'items' => $book['snapshot'],
                'edition' => $book['edition'],
                'edition_label' => $book['edition_label'],
                'published_at' => $book['published_at'],
                'document_id' => $book['document_id'],
            ],
        ];
    }
}
