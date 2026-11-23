<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Internships\Documents\InternshipDocumentTypes;
use Scholaris\Modules\Apps\Higher\Internships\Domain\ConventionStatus;
use Scholaris\Modules\Apps\Higher\Internships\Domain\Grade;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\InternshipStore;

/**
 * Suivi du stage (EX-STG-03) : visites de l'encadreur, rapports d'étape et fiche d'évaluation du tuteur (note /20), cette
 * dernière émise en DOC-23. Possible seulement sur une convention signée (ou terminée).
 */
final class FollowUpService
{
    public const TABLE = 'hi_internship_followups';
    public const KINDS = ['visit', 'report', 'evaluation'];

    public function __construct(
        private readonly InternshipStore $store,
        private readonly InternshipAccess $access,
        private readonly InternshipClock $clock,
        private readonly ConventionService $conventions,
        private readonly InternshipDocuments $documents,
    ) {
    }

    /** @return array<string, mixed> */
    public function add(Principal $principal, string $establishmentId, string $internshipId, string $kind, string $occurredOn, string $summary, ?int $scoreHundredths): array
    {
        $internship = $this->conventions->writable($principal, $establishmentId, $internshipId, InternshipAccess::MANAGE);
        if (!ConventionStatus::isRunning((string) $internship['status'])) {
            throw HttpException::conflict('Le suivi commence une fois la convention signée.');
        }
        if ($kind === 'evaluation' && $scoreHundredths === null) {
            throw HttpException::validation(['score' => ['La fiche d\'évaluation porte une note sur 20.']]);
        }
        $documentId = null;
        if ($kind === 'evaluation') {
            $company = $this->store->findOrFail(CompanyService::TABLE, $principal->tenantId, $establishmentId, (string) $internship['company_id']);
            $documentId = $this->documents->issue($principal->tenantId, $establishmentId, InternshipDocumentTypes::EVALUATION, $internship, InternshipDocuments::internshipData($internship, $company) + [
                'evaluation_date' => $occurredOn, 'evaluation_score' => Grade::label((int) $scoreHundredths), 'evaluation_summary' => $summary,
            ], $principal->userId);
        }
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'internship_id' => $internshipId, 'kind' => $kind, 'occurred_on' => $occurredOn, 'summary' => $summary,
            'score_hundredths' => $scoreHundredths, 'document_id' => $documentId, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'higher.internship.followup_added', 'hi_internship', $internshipId, null, ['kind' => $kind, 'followup_id' => $id]);

        return $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id);
    }
}
