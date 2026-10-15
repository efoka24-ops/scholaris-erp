<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * NOT-04 « inscription validée, matricule attribué » (étudiant et garants, SMS, par Higher\Notifications) et documents de
 * l'inscription par Higher\Documents : fiche d'inscription et contrat pédagogique (DOC-04), carte d'étudiant avec QR (DOC-05).
 * Un échec de notification n'annule jamais la validation ; un échec d'émission demandée explicitement est remonté (422).
 */
final class StudentMessages
{
    public const VALIDATED = 'NOT-04';

    public function __construct(
        private readonly HigherNotifier $notifier,
        private readonly HigherDocumentIssuer $documents,
        private readonly PersonDirectory $people,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $enrolment */
    public function validated(array $enrolment, string $matricule): void
    {
        $tenantId = (string) $enrolment['tenant_id'];
        try {
            $this->notifier->notify($tenantId, (string) $enrolment['establishment_id'], self::VALIDATED, (string) $enrolment['person_id'], [
                'full_name' => $this->people->find($tenantId, (string) $enrolment['person_id'])?->fullName() ?? '',
                'school_year' => (string) $enrolment['academic_year_id'],
                'reference' => $matricule,
            ], 'ins-validated-' . $enrolment['id']);
        } catch (Throwable $e) {
            $this->logger->warning('NOT-04 non envoyée pour l\'inscription ' . $enrolment['id'] . ' : ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $enrolment
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    public function issue(string $code, array $enrolment, ?string $matricule, array $data, string $issuedBy): array
    {
        return $this->documents->issueFor(
            (string) $enrolment['tenant_id'],
            (string) $enrolment['establishment_id'],
            $code,
            (string) $enrolment['person_id'],
            (string) $enrolment['school_id'],
            (string) $enrolment['program_id'],
            (string) $enrolment['level_id'],
            $data + ['academic_year' => (string) $enrolment['academic_year_id'], 'holder_matricule' => (string) $matricule],
            $issuedBy,
        );
    }
}
