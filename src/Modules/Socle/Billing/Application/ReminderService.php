<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\ReconciliationRepository;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Relances de tranches avant et après l'échéance (par défaut J-7, J+1, J+15, paramètre billing.reminder_offsets).
 * Service prévu pour la tâche planifiée `reminders:send` : un appel par jour suffit ; un jour manqué est rattrapé
 * (fenêtre de CATCH_UP_DAYS) et une relance n'est jamais envoyée deux fois pour la même tranche et le même décalage.
 */
final class ReminderService
{
    private const CATCH_UP_DAYS = 2;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ReconciliationRepository $reminders,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly BillingSettings $settings,
        private readonly BillingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{sent: int, skipped: int} relances envoyées et tranches sans destinataire ou déjà relancées */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        $sent = 0;
        $skipped = 0;

        foreach ($this->invoices->activeScopes() as $scope) {
            $tenantId = $scope['tenant_id'];
            $establishmentId = $scope['establishment_id'];
            foreach ($this->settings->reminderOffsets($tenantId, $establishmentId) as $offset) {
                $target = $this->shift($today, -$offset);
                foreach ($this->invoices->dueBetween($tenantId, $establishmentId, $this->shift($target, -self::CATCH_UP_DAYS), $target) as $installment) {
                    if ($this->reminders->reminderExists($tenantId, (string) $installment['id'], $offset)) {
                        ++$skipped;
                        continue;
                    }
                    $this->send($tenantId, $establishmentId, $installment, $offset) ? ++$sent : ++$skipped;
                }
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $installment */
    private function send(string $tenantId, string $establishmentId, array $installment, int $offset): bool
    {
        $delivered = false;
        try {
            $left = (int) $installment['amount_xaf'] - (int) $installment['paid_xaf'];
            foreach ($this->people->guarantorsOf($tenantId, (string) $installment['learner_id']) as $guarantor) {
                $result = $this->messenger->send($tenantId, $establishmentId, $guarantor->id, 'billing.reminder', [
                    'amount' => $left, 'due_on' => (string) $installment['due_on'], 'label' => (string) $installment['label'], 'offset' => $offset,
                ], ['sms']);
                $delivered = $delivered || $result->status !== DeliveryResult::BLOCKED;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Relance non envoyée : ' . $e->getMessage(), ['installment' => $installment['id']]);

            return false;
        }

        $this->reminders->insert('reminders', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'installment_id' => (string) $installment['id'],
            'offset_days' => $offset, 'due_on' => (string) $installment['due_on'], 'status' => $delivered ? 'sent' : 'blocked', 'sent_at' => $this->clock->now(),
        ]);

        return $delivered;
    }

    private function shift(string $date, int $days): string
    {
        $moment = new DateTimeImmutable($date);

        return ($days >= 0 ? $moment->add(new DateInterval('P' . $days . 'D')) : $moment->sub(new DateInterval('P' . -$days . 'D')))->format('Y-m-d');
    }
}
