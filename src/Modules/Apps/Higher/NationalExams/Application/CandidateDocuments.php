<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\NationalExams\Domain\CandidateLifecycle;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamDocumentTypes;

/**
 * Émission des documents d'un candidat par le module Documents (numérotation RG-DOC-1, gardes S18) : DOC-17 dès que le dossier
 * est complet ; DOC-12 pour tout candidat non retiré (la garde de Deliberation exige la délibération annuelle signée).
 */
final class CandidateDocuments
{
    public function __construct(
        private readonly CandidateService $candidates,
        private readonly SessionService $sessions,
        private readonly HigherDocumentIssuer $issuer,
        private readonly NationalExamsAccess $access,
    ) {
    }

    /** @return array<string, mixed> */
    public function issue(Principal $principal, string $establishmentId, string $candidateId, string $code, ?string $locale): array
    {
        [$candidate, $session] = $this->candidates->readable($principal, $establishmentId, $candidateId);
        $this->sessions->writable($principal, $establishmentId, (string) $session['id']);
        if ($candidate['status'] === CandidateLifecycle::WITHDRAWN) {
            throw HttpException::conflict('Candidat retiré de la liste : aucun document.');
        }
        if ($code === NationalExamDocumentTypes::CANDIDATE_RECORD && $candidate['status'] === CandidateLifecycle::ELIGIBLE) {
            throw HttpException::conflict('Fiche de candidature émise seulement pour un dossier complet (assiduité, frais, pièces).');
        }
        // holder_name est fourni par le moteur de documents (variable système, titulaire = candidat).
        $data = ['specialty' => (string) $session['specialty_label']];
        $data += $code === NationalExamDocumentTypes::COMPLETION
            ? ['training_period' => $session['attendance_from'] . ' - ' . $session['attendance_to']]
            : ['candidate_number' => $candidate['candidate_number'], 'exam_centre' => $candidate['exam_centre'],
                'presence_percent' => (string) $candidate['presence_percent'], 'fee_receipt' => $candidate['fee_receipt']];
        $document = $this->issuer->issueFor($principal->tenantId, $establishmentId, $code, (string) $candidate['person_id'], (string) $candidate['school_id'], (string) $candidate['program_id'], (string) $candidate['level_id'], $data, $principal->userId, $locale);
        $this->access->audit($principal, 'higher.national_candidate.document', 'national_candidate', $candidateId, null, ['code' => $code, 'document_id' => $document['id'] ?? null]);

        return $document;
    }
}
