<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Portals\Student;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/** Section « higher_documents » du portail étudiant du socle (S20) : documents de l'IPES valides de l'étudiant. */
final class DocumentsSection implements PortalSection
{
    public function __construct(private readonly IssuedDocuments $documents)
    {
    }

    public function key(): string
    {
        return 'higher_documents';
    }

    public function portals(): array
    {
        return [self::LEARNER];
    }

    public function build(PortalSectionContext $context): ?array
    {
        $valid = array_values(array_filter(
            $this->documents->forPerson($context->tenantId, $context->subjectPersonId),
            static fn (array $d): bool => ($d['status'] ?? null) === 'valid',
        ));

        return $valid === [] ? null : ['documents' => $valid];
    }
}
