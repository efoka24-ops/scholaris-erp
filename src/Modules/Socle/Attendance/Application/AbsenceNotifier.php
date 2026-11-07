<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Prévient les garants le jour même, à la validation (synchronisation) de l'appel : seulement pour une absence non justifiée,
 * une seule fois par élève et par jour (réservation unique en base), selon le paramètre de l'établissement.
 * Une panne de messagerie ne remet jamais en cause la validation de l'appel.
 */
final class AbsenceNotifier
{
    public const TEMPLATE = 'absence_notice';

    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly AttendanceSettings $settings,
        private readonly AttendanceClock $clock,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly Messenger $messenger,
    ) {
    }

    /**
     * @param array<string, mixed> $session
     * @param list<array<string, mixed>> $records
     * @return int nombre d'élèves pour lesquels une notification a été demandée
     */
    public function notify(array $session, array $records): int
    {
        $tenantId = (string) $session['tenant_id'];
        $establishmentId = (string) $session['establishment_id'];
        if (!$this->settings->notifyParents($tenantId, $establishmentId)) {
            return 0;
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $establishmentName = $establishment === null ? '' : $establishment->name;
        $date = (string) $session['session_date'];
        $count = 0;

        foreach ($records as $record) {
            if ($record['status'] !== 'A' || (int) $record['justified'] === 1) {
                continue;
            }
            $learnerId = (string) $record['person_id'];
            $claimId = (string) new Ulid();
            if (!$this->repository->claimNotification($tenantId, $establishmentId, $learnerId, $date, (string) $session['id'], $claimId, $this->clock->now())) {
                continue;
            }
            ++$count;
            $this->repository->finishNotification($tenantId, $claimId, ...$this->send($tenantId, $establishmentId, $learnerId, $date, $establishmentName));
        }

        return $count;
    }

    /**
     * Rejoue les notifications d'absence restées en échec (panne de messagerie). Une notification n'est reprise que par
     * un seul rejeu à la fois ; si elle échoue encore, elle reste « failed » pour un prochain passage.
     *
     * @param string|null $tenantId limite le rejeu à un client ; null = tous les clients
     * @return array{retried: int, sent: int, still_failed: int}
     */
    public function retryFailed(?string $tenantId = null, int $limit = 100): array
    {
        $result = ['retried' => 0, 'sent' => 0, 'still_failed' => 0];
        $names = [];
        foreach ($this->repository->failedNotifications($tenantId, $limit) as $row) {
            $tenant = (string) $row['tenant_id'];
            $establishmentId = (string) $row['establishment_id'];
            $id = (string) $row['id'];
            if (!$this->repository->reopenNotification($tenant, $id)) {
                continue;
            }
            $names[$tenant . '/' . $establishmentId] ??= $this->establishments->find($tenant, $establishmentId)->name ?? '';
            [$status, $recipients] = $this->send($tenant, $establishmentId, (string) $row['person_id'], (string) $row['absence_date'], $names[$tenant . '/' . $establishmentId]);
            $this->repository->finishNotification($tenant, $id, $status, $recipients);
            ++$result['retried'];
            $status === 'sent' ? ++$result['sent'] : ($status === 'failed' ? ++$result['still_failed'] : null);
        }

        return $result;
    }

    /** @return array{0: string, 1: int} statut et nombre de destinataires atteints */
    private function send(string $tenantId, string $establishmentId, string $learnerId, string $date, string $establishmentName): array
    {
        $child = $this->people->find($tenantId, $learnerId);
        $guarantors = $this->people->guarantorsOf($tenantId, $learnerId);
        if ($child === null || $guarantors === []) {
            return ['no_recipient', 0];
        }
        $reached = 0;
        $failed = false;
        foreach ($guarantors as $guarantor) {
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $guarantor->id, self::TEMPLATE, [
                    'child_name' => $child->fullName(),
                    'absence_date' => $date,
                    'establishment_name' => $establishmentName,
                ]);
                if ($result->status !== DeliveryResult::BLOCKED) {
                    ++$reached;
                }
            } catch (Throwable) {
                $failed = true;
            }
        }
        if ($reached > 0) {
            return ['sent', $reached];
        }

        return [$failed ? 'failed' : 'blocked', 0];
    }
}
