<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Reporting\Domain\DashboardKind;
use Scholaris\Modules\Socle\Reporting\Domain\Frequency;
use Scholaris\Modules\Socle\Reporting\Domain\ReportType;
use Scholaris\Modules\Socle\Reporting\Infrastructure\ScheduleRepository;
use Throwable;

/**
 * Rapports planifiés : définition (type, périodicité, destinataires) et exécution. Une exécution calcule les indicateurs, envoie le
 * résumé par e-mail à chaque destinataire via Messenger (qui applique consentement et plages horaires) et journalise le résultat.
 * L'exécution planifiée est idempotente par rapport et par jour : rejouer la commande le même jour n'envoie rien de plus.
 */
final class ScheduledReportService
{
    public const TEMPLATE = 'scheduled_report';
    public const MAX_RECIPIENTS = 20;

    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly IndicatorService $indicators,
        private readonly RegulatoryStatistics $regulatory,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly Messenger $messenger,
        private readonly AuditLog $audit,
        private readonly ReportingClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data name, report_type, frequency, run_day, recipient_ids
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data, string $actorId): array
    {
        $frequency = Frequency::from((string) $data['frequency']);
        $runDay = isset($data['run_day']) ? (int) $data['run_day'] : null;
        $this->assertDay($frequency, $runDay);
        $recipients = $this->recipients($tenantId, (array) $data['recipient_ids']);
        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $this->schedules->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'name' => $data['name'],
            'report_type' => $data['report_type'], 'frequency' => $frequency->value, 'run_day' => $runDay,
            'recipient_ids' => json_encode($recipients, JSON_THROW_ON_ERROR), 'status' => 'active',
            'next_run_on' => $frequency->nextAfter($this->clock->today(), $runDay), 'last_run_at' => null, 'last_run_status' => null,
            'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes name, frequency, run_day, recipient_ids, status
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $id, array $changes): array
    {
        $row = $this->get($tenantId, $establishmentId, $id);
        $values = [];
        if (isset($changes['name'])) {
            $values['name'] = $changes['name'];
        }
        if (isset($changes['status'])) {
            $values['status'] = $changes['status'];
        }
        if (array_key_exists('recipient_ids', $changes)) {
            $values['recipient_ids'] = json_encode($this->recipients($tenantId, (array) $changes['recipient_ids']), JSON_THROW_ON_ERROR);
        }
        if (isset($changes['frequency']) || array_key_exists('run_day', $changes)) {
            $frequency = Frequency::from((string) ($changes['frequency'] ?? $row['frequency']));
            $runDay = array_key_exists('run_day', $changes) ? ($changes['run_day'] === null ? null : (int) $changes['run_day']) : ($row['run_day'] === null ? null : (int) $row['run_day']);
            $this->assertDay($frequency, $runDay);
            $values['frequency'] = $frequency->value;
            $values['run_day'] = $runDay;
            $values['next_run_on'] = $frequency->nextAfter($this->clock->today(), $runDay);
        } elseif (($changes['status'] ?? null) === 'active' && $row['status'] !== 'active') {
            $values['next_run_on'] = Frequency::from((string) $row['frequency'])->nextAfter($this->clock->today(), $row['run_day'] === null ? null : (int) $row['run_day']);
        }
        $values['updated_at'] = $this->clock->iso();
        $this->schedules->update($tenantId, $establishmentId, $id, $values);

        return $this->get($tenantId, $establishmentId, $id);
    }

    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->get($tenantId, $establishmentId, $id);
        $this->schedules->delete($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->schedules->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Rapport planifié introuvable.');

        return $this->present($row);
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?string} */
    public function list(string $tenantId, string $establishmentId, ?string $afterId, int $perPage): array
    {
        $rows = $this->schedules->forEstablishment($tenantId, $establishmentId, $afterId, $perPage + 1);
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $next = (string) $rows[count($rows) - 1]['id'];
        }

        return [array_map($this->present(...), $rows), $next];
    }

    /** @return list<array<string, mixed>> */
    public function runs(string $tenantId, string $establishmentId, string $id): array
    {
        $this->get($tenantId, $establishmentId, $id);

        return $this->schedules->runs($tenantId, $establishmentId, $id, 50);
    }

    /**
     * Exécution manuelle immédiate (chaque appel envoie).
     *
     * @return array<string, mixed>
     */
    public function runNow(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $row = $this->schedules->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Rapport planifié introuvable.');

        return $this->execute($row, 'manual-' . new Ulid(), 'manual', $actorId) ?? [];
    }

    /**
     * Exécute les rapports échus (commande console). Idempotent : une échéance déjà jouée est ignorée.
     *
     * @return list<array<string, mixed>> résultat de chaque rapport traité
     */
    public function runDue(?string $tenantId = null, ?string $today = null): array
    {
        $today ??= $this->clock->today();
        $results = [];
        foreach ($this->schedules->due($today, $tenantId) as $row) {
            $result = $this->execute($row, $today, 'scheduled', 'system');
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null null si cette échéance a déjà été jouée
     */
    private function execute(array $row, string $runKey, string $trigger, string $actorId): ?array
    {
        $tenantId = (string) $row['tenant_id'];
        $establishmentId = (string) $row['establishment_id'];
        $scheduleId = (string) $row['id'];
        $runId = (string) new Ulid();
        $now = $this->clock->iso();
        $frequency = Frequency::from((string) $row['frequency']);
        $runDay = $row['run_day'] === null ? null : (int) $row['run_day'];
        $asOf = $trigger === 'scheduled' ? $runKey : $this->clock->today();

        if (!$this->schedules->claimRun($runId, $tenantId, $establishmentId, $scheduleId, $runKey, $trigger, $now)) {
            // échéance déjà jouée : on se contente de faire avancer la prochaine échéance
            $this->schedules->update($tenantId, $establishmentId, $scheduleId, [
                'next_run_on' => $frequency->nextAfter($asOf, $runDay),
            ]);

            return null;
        }

        $sent = 0;
        $failed = 0;
        $summary = '';
        $status = 'done';
        try {
            [$label, $summary] = $this->compose($row);
            $name = $this->establishmentName($tenantId, $establishmentId);
            $variables = [
                'label' => mb_substr((string) $row['name'], 0, 120),
                'date' => $asOf,
                'details' => mb_substr($label . ' - ' . $summary, 0, 190),
                'establishment_name' => $name,
            ];
            foreach ($this->decodeRecipients($row) as $personId) {
                try {
                    $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE, $variables, ['email']);
                    $result->status === DeliveryResult::BLOCKED ? ++$failed : ++$sent;
                } catch (Throwable) {
                    ++$failed;
                }
            }
            if ($sent === 0 && $failed > 0) {
                $status = 'failed';
            } elseif ($failed > 0) {
                $status = 'partial';
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $summary = 'Calcul impossible : ' . mb_substr($e->getMessage(), 0, 200);
        }

        $this->schedules->finishRun($tenantId, $runId, $status, $sent, $failed, mb_substr($summary, 0, 500));
        $this->schedules->update($tenantId, $establishmentId, $scheduleId, [
            'last_run_at' => $now, 'last_run_status' => $status,
            'next_run_on' => $frequency->nextAfter($asOf, $runDay), 'updated_at' => $now,
        ]);
        $this->audit->append($tenantId, $actorId, 'report_schedule.run', 'report_schedule', $scheduleId, null, [
            'run_key' => $runKey, 'trigger' => $trigger, 'status' => $status, 'sent' => $sent, 'failed' => $failed,
        ]);

        return ['schedule_id' => $scheduleId, 'tenant_id' => $tenantId, 'run_key' => $runKey, 'status' => $status, 'sent_count' => $sent, 'failed_count' => $failed];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string} intitulé et résumé d'une ligne
     */
    private function compose(array $row): array
    {
        $tenantId = (string) $row['tenant_id'];
        $establishmentId = (string) $row['establishment_id'];
        $type = ReportType::from((string) $row['report_type']);
        if ($type === ReportType::Regulatory) {
            return [$type->label(), $this->regulatory->summary($tenantId, $establishmentId)];
        }
        $kind = $type->dashboard() ?? throw new \LogicException('Type de rapport inconnu.');
        $group = $kind === DashboardKind::Groupe;
        $data = $this->indicators->build($kind, $tenantId, $group ? null : $establishmentId);
        /** @var list<array<string, mixed>> $items */
        $items = $group ? $data['consolidated'] : $data['indicators'];
        $parts = [];
        foreach ($items as $item) {
            $parts[] = $item['available'] === true
                ? sprintf('%s : %s', (string) $item['label'], (string) $item['value'])
                : sprintf('%s : indisponible', (string) $item['label']);
        }

        return [$type->label(), implode(' ; ', $parts)];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function decodeRecipients(array $row): array
    {
        $decoded = json_decode((string) $row['recipient_ids'], true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $row['id'], 'establishment_id' => $row['establishment_id'], 'name' => $row['name'], 'report_type' => $row['report_type'],
            'frequency' => $row['frequency'], 'run_day' => $row['run_day'] === null ? null : (int) $row['run_day'],
            'recipient_ids' => $this->decodeRecipients($row), 'status' => $row['status'], 'next_run_on' => $row['next_run_on'],
            'last_run_at' => $row['last_run_at'], 'last_run_status' => $row['last_run_status'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param array<mixed> $ids
     * @return list<string>
     */
    private function recipients(string $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === [] || count($ids) > self::MAX_RECIPIENTS) {
            throw HttpException::validation(['recipient_ids' => [sprintf('Entre 1 et %d destinataires sont attendus.', self::MAX_RECIPIENTS)]]);
        }
        $known = array_map(static fn ($person): string => $person->id, $this->people->findMany($tenantId, $ids));
        $unknown = array_diff($ids, $known);
        if ($unknown !== []) {
            throw HttpException::validation(['recipient_ids' => ['Destinataire inconnu : ' . implode(', ', $unknown) . '.']]);
        }

        return $ids;
    }

    private function assertDay(Frequency $frequency, ?int $day): void
    {
        if (!$frequency->acceptsDay($day)) {
            throw HttpException::validation(['run_day' => [match ($frequency) {
                Frequency::Daily => 'Une périodicité quotidienne n\'a pas de jour d\'exécution.',
                Frequency::Weekly => 'Le jour d\'exécution hebdomadaire va de 1 (lundi) à 7 (dimanche).',
                Frequency::Monthly => 'Le jour d\'exécution mensuel va de 1 à 28.',
            }]]);
        }
    }

    private function establishmentName(string $tenantId, string $establishmentId): string
    {
        return $this->establishments->find($tenantId, $establishmentId)->name ?? $establishmentId;
    }
}
