<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Apee\Portal;

use Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure\PdoApee;
use Scholaris\Modules\Apps\PublicSchools\Support\WriteGate;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Section « reçus APEE » du portail famille (EX-COM-04, R13 : ses reçus seulement, T641). Distincte des sections scolaires : la
 * contribution est volontaire et n'apparaît sur aucun document d'élève (RG-DOC-3). Aucune famille ne voit les reçus d'une autre.
 */
final class ReceiptsSection implements PortalSection
{
    public function __construct(private readonly PdoApee $repository, private readonly WriteGate $gate)
    {
    }

    public function key(): string
    {
        return 'public_schools_apee_receipts';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        if (!$this->gate->allowsReads($context->tenantId)) {
            return null;
        }
        $rows = $this->repository->receiptsOfFamily($context->tenantId, $context->subjectPersonId);
        if ($rows === []) {
            return null;
        }

        return ['receipts' => array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'year_label' => (string) $row['year_label'], 'amount' => (int) $row['amount'], 'method' => $row['method'],
            'entered_on' => (string) $row['entered_on'], 'receipt_number' => $row['receipt_number'], 'document_id' => $row['receipt_document_id'],
        ], $rows)];
    }
}
