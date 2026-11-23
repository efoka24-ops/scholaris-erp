<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Domain\ExpiryThresholds;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Alertes d'expiration des autorisations MINESUP et des conventions de tutelle (EX-ACA-07) : à 90, 30 et 7 jours de l'échéance
 * (paramètre `higher.alerts.expiry_days`), message `higher.expiry_alert` envoyé par Messenger aux personnes de la direction
 * (DirectionDirectory) et aux destinataires paramétrés (`higher.alerts.expiry_recipients`, identifiants de personnes).
 * Un client dont l'application ne permet pas l'écriture (suspendue, fermée) est sauté (WriteGate). Chaque seuil n'est envoyé
 * qu'une fois par échéance (hi_expiry_alerts) ; une autorisation renouvelée (nouvelle échéance) repart de zéro.
 */
final class ExpiryAlertService
{
    public const TEMPLATE = 'higher.expiry_alert';

    public function __construct(
        private readonly Store $store,
        private readonly AuthorizationRegistry $registry,
        private readonly Messenger $messenger,
        private readonly DirectionDirectory $direction,
        private readonly SettingsReader $settings,
        private readonly WriteGate $gate,
        private readonly AcademicClock $clock,
    ) {
    }

    /** @return array{alerts: int, messages: int, skipped_tenants: int} */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        $result = ['alerts' => 0, 'messages' => 0, 'skipped_tenants' => 0];
        $scopes = [];
        foreach ([...$this->store->scopes('hi_authorizations'), ...$this->store->scopes('hi_tutelages')] as [$tenantId, $establishmentId]) {
            $scopes[$tenantId . "\0" . $establishmentId] = [$tenantId, $establishmentId];
        }
        $skipped = [];
        foreach ($scopes as [$tenantId, $establishmentId]) {
            if (!$this->gate->allowsWrites($tenantId)) {
                $skipped[$tenantId] = true;
                continue;
            }
            foreach ($this->subjects($tenantId, $establishmentId) as $subject) {
                $sent = $this->alert($tenantId, $establishmentId, $subject, $today);
                if ($sent !== null) {
                    $result['alerts']++;
                    $result['messages'] += $sent;
                }
            }
        }
        $result['skipped_tenants'] = count($skipped);

        return $result;
    }

    /** @return list<array{type: string, id: string, expires_on: string, label: string, reference: string}> */
    private function subjects(string $tenantId, string $establishmentId): array
    {
        $programs = [];
        foreach ($this->store->select('hi_programs', $tenantId, $establishmentId, [], 'id', 10000) as $program) {
            $programs[(string) $program['id']] = (string) $program['name'];
        }
        $subjects = [];
        foreach ($this->store->select('hi_authorizations', $tenantId, $establishmentId, [], 'id', 10000) as $row) {
            $info = $this->registry->find($tenantId, $establishmentId, (string) $row['id']);
            if ($info === null) {
                continue;
            }
            $subjects[] = [
                'type' => 'authorization', 'id' => (string) $row['id'], 'expires_on' => $info->validTo,
                'label' => 'Autorisation MINESUP ' . $info->reference . ' (' . ($programs[(string) $row['program_id']] ?? 'filière') . ')',
                'reference' => $info->reference,
            ];
        }
        foreach ($this->store->select('hi_tutelages', $tenantId, $establishmentId, [], 'id', 10000) as $row) {
            if (!is_string($row['convention_ends_on']) || $row['convention_ends_on'] === '') {
                continue;
            }
            $subjects[] = [
                'type' => 'tutelage', 'id' => (string) $row['id'], 'expires_on' => (string) $row['convention_ends_on'],
                'label' => 'Convention de tutelle ' . $row['university_name'] . ' (' . ($programs[(string) $row['program_id']] ?? 'filière') . ')',
                'reference' => (string) ($row['convention_reference'] ?? ''),
            ];
        }

        return $subjects;
    }

    /**
     * @param array{type: string, id: string, expires_on: string, label: string, reference: string} $subject
     * @return int|null nombre de messages envoyés, null si aucune alerte n'était due
     */
    private function alert(string $tenantId, string $establishmentId, array $subject, string $today): ?int
    {
        $thresholds = new ExpiryThresholds((array) $this->settings->value($tenantId, $establishmentId, 'higher.alerts.expiry_days', ExpiryThresholds::DEFAULT));
        $already = array_map(
            static fn (array $row): int => (int) $row['threshold_days'],
            $this->store->select('hi_expiry_alerts', $tenantId, $establishmentId, ['subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'expires_on' => $subject['expires_on']]),
        );
        $daysLeft = ExpiryThresholds::daysBetween($today, $subject['expires_on']);
        $due = $thresholds->due($daysLeft, $already);
        $recipients = $this->recipients($tenantId, $establishmentId);
        if ($due === null || $recipients === []) {
            return null;
        }
        [$threshold, $marked] = $due;
        $sent = 0;
        $statuses = [];
        foreach ($recipients as $personId) {
            $delivery = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE, [
                'label' => mb_substr($subject['label'], 0, 200), 'reference' => $subject['reference'], 'due_date' => $subject['expires_on'],
                'offset' => (string) $daysLeft,
            ]);
            $statuses[$delivery->status] = true;
            if ($delivery->status !== DeliveryResult::BLOCKED) {
                $sent++;
            }
        }
        $status = $sent > 0 ? (isset($statuses[DeliveryResult::SENT]) ? DeliveryResult::SENT : DeliveryResult::QUEUED) : DeliveryResult::BLOCKED;
        $now = $this->clock->now();
        foreach ($marked as $days) {
            $this->store->insert('hi_expiry_alerts', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'expires_on' => $subject['expires_on'],
                'threshold_days' => $days, 'recipients_count' => $days === $threshold ? count($recipients) : 0, 'delivery_status' => $status, 'sent_at' => $now,
            ]);
        }

        return $sent;
    }

    /** @return list<string> */
    private function recipients(string $tenantId, string $establishmentId): array
    {
        $configured = $this->settings->value($tenantId, $establishmentId, 'higher.alerts.expiry_recipients', []);
        $ids = [];
        foreach ([...(is_array($configured) ? $configured : []), ...$this->direction->directionPersonIds($tenantId, $establishmentId)] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
