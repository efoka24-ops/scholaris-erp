<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Infrastructure\PrivacyDb;

/** Demandes des personnes concernées : accès, rectification, effacement (avec double validation), portabilité. */
final class DataRequestService
{
    public const KINDS = ['access', 'rectification', 'erasure', 'portability'];
    public const RECEIVED = 'received';
    public const IN_PROGRESS = 'in_progress';
    public const PROCESSED = 'processed';
    public const REFUSED = 'refused';
    public const ERASURE_APPROVAL = 'privacy.erasure';

    public function __construct(
        private readonly PrivacyDb $db,
        private readonly AuditLog $audit,
        private readonly Approvals $approvals,
        private readonly PrivacySettings $settings,
        private readonly DataSources $sources,
        private readonly Clock $clock,
        private readonly string $timezone = 'Africa/Douala',
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $tenantId, string $kind, string $subjectPersonId, ?string $details, string $by): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw HttpException::validation(['kind' => ['Type de demande inconnu : ' . implode(', ', self::KINDS) . '.']]);
        }
        $id = (string) new Ulid();
        $days = $this->settings->get($tenantId, 'deadline_days');
        $this->db->exec(
            'INSERT INTO data_subject_requests (id, tenant_id, kind, subject_person_id, details, status, received_at, due_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $kind, $subjectPersonId, $details, self::RECEIVED, $this->moment(0), $this->moment($days * 86400), $by],
        );
        $this->audit->append($tenantId, $by, 'privacy.request.received', 'data_subject_request', $id, null, ['kind' => $kind, 'subject_person_id' => $subjectPersonId]);

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $id): array
    {
        return $this->db->row('SELECT * FROM data_subject_requests WHERE tenant_id = ? AND id = ?', [$tenantId, $id])
            ?? throw HttpException::notFound('Demande introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, ?string $status, ?string $kind, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM data_subject_requests WHERE tenant_id = ?';
        $params = [$tenantId];
        foreach (['status' => $status, 'kind' => $kind] as $column => $value) {
            if ($value !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }

        return $this->db->rows($sql . sprintf(' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset), $params);
    }

    /** @return array<string, mixed> */
    public function start(string $tenantId, string $id, string $by): array
    {
        $request = $this->get($tenantId, $id);
        $this->transition($tenantId, $id, [self::RECEIVED], self::IN_PROGRESS, ['started_at' => $this->moment(0)]);
        $approvalId = null;
        if ($request['kind'] === 'erasure') {
            $approvalId = $this->approvals->request($tenantId, self::ERASURE_APPROVAL, 'data_subject_request', $id, 'Effacement des données de la personne ' . $request['subject_person_id'], $by);
            $this->db->exec('UPDATE data_subject_requests SET approval_id = ? WHERE tenant_id = ? AND id = ?', [$approvalId, $tenantId, $id]);
        }
        $this->audit->append($tenantId, $by, 'privacy.request.started', 'data_subject_request', $id, ['status' => self::RECEIVED], ['status' => self::IN_PROGRESS, 'approval_id' => $approvalId]);

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function decideErasure(string $tenantId, string $id, bool $approve, string $by): array
    {
        $request = $this->get($tenantId, $id);
        if ($request['kind'] !== 'erasure' || $request['approval_id'] === null || $request['status'] !== self::IN_PROGRESS) {
            throw HttpException::conflict('Aucune approbation d\'effacement n\'est en attente pour cette demande.');
        }
        if ($approve) {
            $this->approvals->approve($tenantId, (string) $request['approval_id'], $by);
        } else {
            $this->approvals->reject($tenantId, (string) $request['approval_id'], $by);
            $this->refuse($tenantId, $id, 'Effacement non approuvé par le second validateur.', $by);
        }

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function refuse(string $tenantId, string $id, string $reason, string $by): array
    {
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => ['Le motif du refus est obligatoire.']]);
        }
        $this->transition($tenantId, $id, [self::RECEIVED, self::IN_PROGRESS], self::REFUSED, [
            'refusal_reason' => $reason, 'decided_by' => $by, 'processed_at' => $this->moment(0),
        ]);
        $this->audit->append($tenantId, $by, 'privacy.request.refused', 'data_subject_request', $id, null, ['reason' => $reason]);

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function complete(string $tenantId, string $id, ?string $resolution, string $by): array
    {
        $request = $this->get($tenantId, $id);
        if ($request['status'] !== self::IN_PROGRESS) {
            throw HttpException::conflict('Seule une demande en cours peut être clôturée.');
        }
        $summary = $resolution;
        if ($request['kind'] === 'erasure') {
            $approval = $request['approval_id'] === null ? null : $this->approvals->find($tenantId, (string) $request['approval_id']);
            if ($approval === null || $approval['status'] !== Approvals::APPROVED) {
                throw HttpException::conflict('L\'effacement exige l\'approbation préalable d\'une seconde personne.');
            }
            $counts = [];
            foreach ($this->sources->subjectSources() as $source) {
                $counts[$source->name()] = $source->anonymize($tenantId, (string) $request['subject_person_id']);
            }
            $summary = trim(($resolution ?? '') . ' Anonymisation : ' . json_encode($counts, JSON_UNESCAPED_UNICODE));
            $this->db->exec('UPDATE data_subject_requests SET details = NULL WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
        }
        $this->transition($tenantId, $id, [self::IN_PROGRESS], self::PROCESSED, [
            'resolution' => $summary, 'decided_by' => $by, 'processed_at' => $this->moment(0),
        ]);
        $this->audit->append($tenantId, $by, 'privacy.request.processed', 'data_subject_request', $id, ['status' => self::IN_PROGRESS], ['status' => self::PROCESSED]);

        return $this->get($tenantId, $id);
    }

    /**
     * Données de la personne (accès ou portabilité), calculées à la demande et jamais stockées.
     *
     * @return array<string, mixed>
     */
    public function subjectData(string $tenantId, string $id, string $by): array
    {
        $request = $this->get($tenantId, $id);
        if (!in_array($request['kind'], ['access', 'portability'], true)) {
            throw HttpException::conflict('Seules les demandes d\'accès et de portabilité donnent lieu à une extraction.');
        }
        if (!in_array($request['status'], [self::IN_PROGRESS, self::PROCESSED], true)) {
            throw HttpException::conflict('La demande doit être prise en charge avant l\'extraction.');
        }
        $data = [];
        foreach ($this->sources->subjectSources() as $source) {
            $data[$source->name()] = $source->collect($tenantId, (string) $request['subject_person_id']);
        }
        $this->audit->append($tenantId, $by, 'privacy.request.exported', 'data_subject_request', $id, null, ['sources' => array_keys($data)]);

        return [
            'request_id' => $id,
            'kind' => $request['kind'],
            'subject_person_id' => $request['subject_person_id'],
            'format' => 'application/json',
            'generated_at' => $this->moment(0),
            'data' => $data,
        ];
    }

    /** @param array<string, mixed> $request */
    public function isOverdue(array $request): bool
    {
        return in_array($request['status'], [self::RECEIVED, self::IN_PROGRESS], true) && strtotime((string) $request['due_at']) < $this->clock->now();
    }

    /**
     * @param list<string> $from
     * @param array<string, scalar|null> $set
     */
    private function transition(string $tenantId, string $id, array $from, string $to, array $set): void
    {
        $assignments = ['status = ?'];
        $params = [$to];
        foreach ($set as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $marks = implode(', ', array_fill(0, count($from), '?'));
        $updated = $this->db->exec(
            'UPDATE data_subject_requests SET ' . implode(', ', $assignments) . ' WHERE tenant_id = ? AND id = ? AND status IN (' . $marks . ')',
            [...$params, $tenantId, $id, ...$from],
        );
        if ($updated === 0) {
            $this->get($tenantId, $id);
            throw HttpException::conflict('Cette demande ne peut plus changer d\'état de cette façon.');
        }
    }

    private function moment(int $offsetSeconds): string
    {
        return (new DateTimeImmutable('@' . ($this->clock->now() + $offsetSeconds)))->setTimezone(new DateTimeZone($this->timezone))->format(DATE_ATOM);
    }
}
