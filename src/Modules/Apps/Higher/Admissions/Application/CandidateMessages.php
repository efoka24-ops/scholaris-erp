<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Notifications (NOT-01 à NOT-03, par Higher\Notifications sur le Messenger du socle : SMS et e-mail) et documents automatiques
 * (DOC-01 accusé de réception, DOC-02 convocation, DOC-03 lettre de décision, par Higher\Documents) du candidat.
 * Un échec d'envoi ou d'émission automatique n'annule jamais l'opération métier : il est journalisé et le document reste à émettre.
 */
final class CandidateMessages
{
    public const RECEIVED = 'NOT-01';
    public const DOCUMENT_REJECTED = 'NOT-02';
    public const DECISION = 'NOT-03';

    public function __construct(
        private readonly HigherNotifier $notifier,
        private readonly HigherDocumentIssuer $documents,
        private readonly PersonDirectory $people,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $application */
    public function notify(string $code, array $application, string $label, string $dedupeKey): void
    {
        $tenantId = (string) $application['tenant_id'];
        $person = $this->people->find($tenantId, (string) $application['person_id']);
        try {
            $this->notifier->notify($tenantId, (string) $application['establishment_id'], $code, (string) $application['person_id'], [
                'full_name' => $person?->fullName() ?? '',
                'reference' => (string) $application['reference'],
                'label' => $label,
                'link' => '/portail/candidatures/' . $application['reference'],
            ], $dedupeKey);
        } catch (Throwable $e) {
            $this->logger->warning('Notification ' . $code . ' non envoyée pour la candidature ' . $application['reference'] . ' : ' . $e->getMessage());
        }
    }

    /**
     * Émission automatique d'un document du candidat ; null si l'émission échoue (profil documentaire de l'école absent…).
     *
     * @param array<string, mixed> $application
     * @param array<string, scalar|null> $data
     */
    public function issue(string $code, array $application, array $data, string $issuedBy): ?string
    {
        try {
            $document = $this->documents->issueFor(
                (string) $application['tenant_id'],
                (string) $application['establishment_id'],
                $code,
                (string) $application['person_id'],
                (string) $application['school_id'],
                (string) $application['program_id'],
                (string) $application['level_id'],
                $data + ['academic_year' => (string) $application['academic_year_id']],
                $issuedBy,
            );

            return isset($document['id']) ? (string) $document['id'] : null;
        } catch (Throwable $e) {
            $this->logger->warning('Document ' . $code . ' non émis pour la candidature ' . $application['reference'] . ' : ' . $e->getMessage());

            return null;
        }
    }

}
