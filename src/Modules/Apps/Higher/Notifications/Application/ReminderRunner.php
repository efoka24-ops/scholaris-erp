<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Relances de l'échéancier (RG-FIN-3) adressées à l'étudiant : J-7 avant la date limite (NOT-08), J+1 et J+15 après (NOT-09),
 * décalages du paramètre `billing.reminder_offsets` (défaut -7, 1, 15). Une tranche soldée n'est jamais relancée ; chaque relance
 * n'est envoyée qu'une fois par tranche, décalage et destinataire. Les garants reçoivent déjà la relance du socle
 * (`billing.reminder`) ; ils reçoivent aussi NOT-08/09 si `higher.notifications.reminders_to_guarantors` est vrai.
 * Un client dont l'application est suspendue est sauté (WriteGate).
 */
final class ReminderRunner
{
    public const OFFSETS_KEY = 'billing.reminder_offsets';
    public const DEFAULT_OFFSETS = [-7, 1, 15];

    public function __construct(
        private readonly StudentAccounts $accounts,
        private readonly HigherNotifier $notifier,
        private readonly SettingsReader $settings,
        private readonly WriteGate $gate,
        private readonly Clock $clock,
    ) {
    }

    public function today(): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'))->format('Y-m-d');
    }

    /** @return array{sent: int, duplicates: int, blocked: int, reminders: list<array<string, mixed>>} */
    public function runFor(string $tenantId, string $establishmentId, ?string $today = null): array
    {
        $today ??= $this->today();
        $offsets = $this->offsets($tenantId, $establishmentId);
        $summary = ['sent' => 0, 'duplicates' => 0, 'blocked' => 0, 'reminders' => []];
        foreach ($this->accounts->forEstablishment($tenantId, $establishmentId) as $account) {
            foreach ($account->installments as $installment) {
                if ($installment['remaining_xaf'] <= 0) {
                    continue;
                }
                foreach ($offsets as $offset) {
                    if (self::shift($installment['due_on'], $offset) !== $today) {
                        continue;
                    }
                    $code = $offset < 0 ? 'NOT-08' : 'NOT-09';
                    $results = $this->notifier->notify($tenantId, $establishmentId, $code, $account->personId, [
                        'amount' => $installment['remaining_xaf'], 'due_on' => $installment['due_on'], 'label' => $installment['label'], 'offset' => $offset,
                    ], 'reminder:' . $installment['id'] . ':' . $offset);
                    foreach ($results as $result) {
                        $key = match ($result['status']) {
                            Notifier::DUPLICATE => 'duplicates',
                            'sent', 'queued' => 'sent',
                            default => 'blocked',
                        };
                        ++$summary[$key];
                    }
                    $summary['reminders'][] = [
                        'notification' => $code, 'offset' => $offset, 'account_id' => $account->id, 'person_id' => $account->personId,
                        'installment_id' => $installment['id'], 'label' => $installment['label'], 'due_on' => $installment['due_on'],
                        'remaining_xaf' => $installment['remaining_xaf'],
                    ];
                }
            }
        }

        return $summary;
    }

    /** Tâche planifiée : tous les établissements qui ont des comptes, clients suspendus ou fermés sautés. */
    public function runAll(?string $today = null): int
    {
        $sent = 0;
        foreach ($this->accounts->activeScopes() as $scope) {
            if (!$this->gate->allowsWrites($scope['tenant_id'])) {
                continue;
            }
            $sent += $this->runFor($scope['tenant_id'], $scope['establishment_id'], $today)['sent'];
        }

        return $sent;
    }

    /** @return list<int> */
    private function offsets(string $tenantId, string $establishmentId): array
    {
        $raw = $this->settings->value($tenantId, $establishmentId, self::OFFSETS_KEY, null);
        if (!is_array($raw)) {
            return self::DEFAULT_OFFSETS;
        }
        $offsets = array_values(array_filter($raw, static fn (mixed $o): bool => is_int($o) && $o >= -60 && $o <= 365));

        return $offsets === [] ? self::DEFAULT_OFFSETS : $offsets;
    }

    public static function shift(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
