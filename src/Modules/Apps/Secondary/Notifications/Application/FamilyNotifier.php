<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Notifications\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\FamilyNotifications;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/** Déclencheurs NOT-01 et NOT-09 : garants de l'élève, modèle secondaire, journal anti-doublon par événement et destinataire. */
final class FamilyNotifier implements FamilyNotifications
{
    public const NOT01 = 'NOT-01';
    public const NOT09 = 'NOT-09';
    public const TEMPLATE_NOT01 = 'sec.not01.enrolment_validated';
    public const TEMPLATE_NOT09 = 'sec.not09.payment_received';
    private const TABLE = 'se_notification_log';

    public function __construct(
        private readonly Messenger $messenger,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
        private readonly EstablishmentDirectory $establishments,
        private readonly OrgUnitTree $tree,
        private readonly CurrentPeriod $periods,
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enrolmentValidated(string $tenantId, string $establishmentId, string $enrollmentId): int
    {
        $enrollment = $this->enrollments->find($tenantId, $enrollmentId);
        if ($enrollment === null || $enrollment->tenantId !== $tenantId || $enrollment->establishmentId !== $establishmentId) {
            return 0;
        }
        $child = $this->people->find($tenantId, $enrollment->personId);
        $class = $enrollment->structureId === null ? null : $this->tree->structure($tenantId, $establishmentId, $enrollment->structureId);
        $period = $this->periods->at($tenantId, $establishmentId, $this->clock->today());
        $year = $period !== null && $period->academicYearId === $enrollment->academicYearId && $period->academicYearLabel !== null ? $period->academicYearLabel : $enrollment->academicYearId;

        return $this->notify($tenantId, $establishmentId, self::NOT01, $enrollmentId, $enrollment->personId, self::TEMPLATE_NOT01, [
            'child_name' => $child === null ? '' : $child->fullName(), 'class_name' => $class === null ? '' : $class->name, 'school_year' => $year,
        ]);
    }

    public function paymentReceived(string $tenantId, string $establishmentId, string $learnerId, string $receiptNumber, int $amountXaf): int
    {
        $child = $this->people->find($tenantId, $learnerId);
        if ($child === null || $receiptNumber === '') {
            return 0;
        }

        return $this->notify($tenantId, $establishmentId, self::NOT09, $receiptNumber, $learnerId, self::TEMPLATE_NOT09, [
            'receipt_number' => $receiptNumber, 'amount' => number_format($amountXaf, 0, ',', ' '), 'child_name' => $child->fullName(),
        ]);
    }

    /** @param array<string, scalar> $variables */
    private function notify(string $tenantId, string $establishmentId, string $code, string $eventRef, string $learnerId, string $template, array $variables): int
    {
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $variables['establishment_name'] = $establishment === null ? '' : $establishment->name;
        $sent = 0;
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $where = ['notification_code' => $code, 'event_ref' => $eventRef, 'recipient_person_id' => $guardian->id];
            if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
                continue;
            }
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $guardian->id, $template, $variables, ['sms']);
            } catch (Throwable $e) {
                $this->logger->warning('Notification ' . $code . ' non envoyée : ' . $e->getMessage(), ['event' => $eventRef]);

                continue;
            }
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'template_key' => $template, 'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
            ]);
            $result->status !== DeliveryResult::BLOCKED && ++$sent;
        }

        return $sent;
    }
}
