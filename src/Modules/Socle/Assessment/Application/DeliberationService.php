<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Délibération en séance (T122, IPES) : tableau étudiant × unités d'enseignement, proposition et décision du jury,
 * quorum, clôture avec procès-verbal signé électroniquement par les membres présents. Une fois close, les décisions
 * ne sont plus modifiables (réouverture exceptionnelle motivée).
 *
 * Une délibération porte une session (normal, resit : S7). Les décisions possibles sont les étiquettes des règles en
 * vigueur (S9). Les gardes (S8) sont consultées à l'ouverture, à la clôture, à la signature et à la réouverture ; la
 * clôture calcule l'empreinte SHA-256 des décisions et numérote le PV ; la réouverture archive le PV précédent.
 */
final class DeliberationService
{
    /** Décisions du moteur standard, libellés par défaut (les règles en vigueur font foi). */
    public const DECISIONS = [RuleEngine::ADMITTED, RuleEngine::COMPENSATED, RuleEngine::RESIT, RuleEngine::ADJOURNED];

    public function __construct(
        private readonly Db $db,
        private readonly ResultRepository $repo,
        private readonly ResultService $results,
        private readonly Roster $roster,
        private readonly AssessmentSettings $settings,
        private readonly ActorDirectory $actors,
        private readonly MinutesIssuer $minutes,
        private readonly AuditLog $audit,
        private readonly AssessmentClock $clock,
        private readonly DeliberationGuards $guards,
    ) {
    }

    /**
     * @param array<string, mixed> $data structure_id, period_id, period_label, president_id, members_expected, exam_session
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $structureId = (string) $data['structure_id'];
        $periodId = (string) $data['period_id'];
        $session = (string) ($data['exam_session'] ?? AssessmentService::NORMAL);
        if ($this->repo->deliberationOf($tenantId, $establishmentId, $structureId, $periodId, $session) !== null) {
            throw HttpException::conflict($session === AssessmentService::NORMAL
                ? 'Une délibération existe déjà pour cette classe et cette période.'
                : 'Une délibération de rattrapage existe déjà pour cette classe et cette période.');
        }
        if ($this->roster->students($tenantId, $establishmentId, $structureId) === []) {
            throw HttpException::validation(['structure_id' => ['Aucun étudiant inscrit pour cet établissement dans cette structure.']]);
        }
        $this->guards->assertOpen($principal, $establishmentId, $structureId, $periodId, $session, (string) $data['president_id']);

        $id = (string) new Ulid();
        $this->db->transaction(function () use ($principal, $establishmentId, $data, $tenantId, $structureId, $periodId, $session, $id): void {
            $computed = $this->results->compute($tenantId, $establishmentId, $structureId, $periodId, $data['period_label'] ?? null, $session);
            $this->repo->insert('deliberations', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'structure_id' => $structureId, 'period_id' => $periodId,
                'exam_session' => $session, 'period_label' => $data['period_label'] ?? $periodId, 'status' => 'draft', 'president_id' => $data['president_id'],
                'members_expected' => $data['members_expected'], 'members_present' => '[]', 'quorum_reached' => 0,
                'rule_version' => $computed['rules']->version, 'summary' => '{}', 'minutes' => null, 'minutes_document_id' => null, 'minutes_status' => null,
                'minutes_fingerprint' => null, 'minutes_version' => 0, 'minutes_history' => '[]',
                'signatures' => '[]', 'reopen_reason' => null, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(), 'closed_at' => null,
            ]);
            $this->fillLines($tenantId, $establishmentId, $id, $structureId, $periodId, $session, $computed['rows']);
        });

        return $this->find($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> délibération avec ses lignes, sa synthèse et ses signatures */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->repo->deliberation($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Délibération introuvable.');
        $names = [];
        foreach ($this->roster->students($tenantId, $establishmentId, (string) $row['structure_id']) as $student) {
            $names[$student['person_id']] = $student;
        }
        $lines = [];
        foreach ($this->repo->deliberationLines($tenantId, $id) as $line) {
            $line['name'] = $names[$line['person_id']]['name'] ?? null;
            $line['matricule'] = $names[$line['person_id']]['matricule'] ?? null;
            $line['unit_averages'] = json_decode((string) $line['unit_averages'], true);
            $line['line_extras'] = $line['line_extras'] === null ? null : json_decode((string) $line['line_extras'], true);
            $lines[] = $line;
        }
        $rules = $this->rules($row);
        $row['lines'] = $lines;
        $row['members_present'] = json_decode((string) $row['members_present'], true) ?: [];
        $row['signatures'] = json_decode((string) ($row['signatures'] ?? '[]'), true) ?: [];
        $row['minutes_history'] = json_decode((string) ($row['minutes_history'] ?? '[]'), true) ?: [];
        $row['summary'] = $this->summary($lines, $row, $rules);
        $row['decision_counts'] = $this->decisionCounts($lines, $rules);
        $row['decisions'] = $rules->decisionLabels();

        return $row;
    }

    /**
     * Enregistre les membres présents et calcule le quorum.
     *
     * @param list<string> $present
     * @return array<string, mixed>
     */
    public function attendance(string $tenantId, string $establishmentId, string $id, array $present): array
    {
        $row = $this->mutable($tenantId, $establishmentId, $id, ['draft', 'open']);
        $quorum = $this->hasQuorum($tenantId, $establishmentId, count($present), (int) $row['members_expected']);
        $this->repo->update('deliberations', $tenantId, $id, [
            'members_present' => json_encode($present, JSON_THROW_ON_ERROR), 'quorum_reached' => $quorum ? 1 : 0,
        ]);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * Ouvre la séance : le quorum doit être atteint.
     *
     * @return array<string, mixed>
     */
    public function start(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->mutable($tenantId, $establishmentId, $id, ['draft']);
        $this->assertQuorum($row);
        $this->repo->update('deliberations', $tenantId, $id, ['status' => 'open']);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * Décision du jury : motif au procès-verbal obligatoire si elle diffère de la proposition.
     *
     * @param array<string, mixed> $data decision, reason
     * @return array<string, mixed>
     */
    public function updateLine(string $tenantId, string $establishmentId, string $id, string $lineId, array $data): array
    {
        $row = $this->mutable($tenantId, $establishmentId, $id, ['open']);
        $line = $this->repo->deliberationLine($tenantId, $id, $lineId) ?? throw HttpException::notFound('Ligne de la délibération introuvable.');
        $decision = (string) $data['decision'];
        $allowed = $this->rules($row)->decisionLabels();
        if (!in_array($decision, $allowed, true) && $decision !== $line['proposal']) {
            throw HttpException::validation(['decision' => ['Décision inconnue. Décisions possibles : ' . implode(', ', $allowed) . '.']]);
        }
        $reason = $decision === $line['proposal'] ? null : ($data['reason'] ?? null);
        if ($decision !== $line['proposal'] && ($reason === null || trim((string) $reason) === '')) {
            throw HttpException::validation(['reason' => ['La décision du jury diffère de la proposition : indiquez le motif, il figurera au procès-verbal.']]);
        }
        $this->repo->update('deliberation_lines', $tenantId, $lineId, ['decision' => $decision, 'reason' => $reason]);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * Clôture : décisions reportées sur les résultats, empreinte des décisions, procès-verbal généré (nouvelle version
     * après une réouverture), signatures attendues.
     *
     * @return array<string, mixed>
     */
    public function close(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->mutable($tenantId, $establishmentId, $id, ['open']);
        $this->assertQuorum($row);
        $this->guards->assert(DeliberationGuard::CLOSE, $principal, $row);
        $session = (string) ($row['exam_session'] ?? AssessmentService::NORMAL);

        $this->db->transaction(function () use ($principal, $tenantId, $establishmentId, $row, $id, $session): void {
            foreach ($this->repo->deliberationLines($tenantId, $id) as $line) {
                $result = $this->repo->resultOf($tenantId, $establishmentId, (string) $line['person_id'], (string) $row['period_id'], $session);
                if ($result !== null) {
                    $this->repo->update('results', $tenantId, (string) $result['id'], ['decision' => $line['decision']]);
                }
            }
            $this->repo->update('deliberations', $tenantId, $id, [
                'status' => 'closed', 'closed_at' => $this->clock->now(), 'signatures' => '[]', 'minutes' => $this->minutesText($tenantId, $establishmentId, $id),
                'minutes_fingerprint' => $this->guards->fingerprint($tenantId, $establishmentId, $id), 'minutes_version' => (int) ($row['minutes_version'] ?? 0) + 1,
            ]);
            $this->audit->append($tenantId, $principal->userId, 'assessment.deliberation.closed', 'deliberation', $id, ['status' => 'open'], ['status' => 'closed']);
        });

        $closed = $this->find($tenantId, $establishmentId, $id);
        $issued = $this->minutes->issue($tenantId, $establishmentId, 'deliberation_minutes', (string) $closed['president_id'], [
            'structure' => (string) $closed['structure_id'], 'period' => (string) $closed['period_label'], 'students' => count($closed['lines']),
            'admitted' => $closed['summary']['admitted'], 'compensated' => $closed['summary']['compensated'], 'resit' => $closed['summary']['resit'], 'adjourned' => $closed['summary']['adjourned'],
        ], $principal->userId);
        $this->repo->update('deliberations', $tenantId, $id, ['minutes_status' => $issued['status'], 'minutes_document_id' => $issued['document_id']]);
        $this->guards->after(DeliberationGuard::CLOSE, $principal, $this->repo->deliberation($tenantId, $establishmentId, $id) ?? $row);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * Signature électronique du procès-verbal par un membre présent.
     *
     * @return array<string, mixed>
     */
    public function sign(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->find($tenantId, $establishmentId, $id);
        if ($row['status'] !== 'closed') {
            throw HttpException::conflict('Le procès-verbal se signe après la clôture de la délibération.');
        }
        $person = $this->actors->personIdOf($tenantId, $principal->userId);
        if ($person === null || !in_array($person, $row['members_present'], true)) {
            throw HttpException::forbidden('Seuls les membres présents à la séance peuvent signer le procès-verbal.');
        }
        foreach ($row['signatures'] as $signature) {
            if ($signature['person_id'] === $person) {
                throw HttpException::conflict('Vous avez déjà signé ce procès-verbal.');
            }
        }
        $raw = $this->repo->deliberation($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Délibération introuvable.');
        $this->guards->assert(DeliberationGuard::SIGN, $principal, $raw);
        $signatures = [...$row['signatures'], ['person_id' => $person, 'user_id' => $principal->userId, 'signed_at' => $this->clock->now()]];
        $this->repo->update('deliberations', $tenantId, $id, ['signatures' => json_encode($signatures, JSON_THROW_ON_ERROR)]);
        $this->audit->append($tenantId, $principal->userId, 'assessment.deliberation.signed', 'deliberation', $id, null, ['person_id' => $person, 'fingerprint' => $raw['minutes_fingerprint'] ?? null]);
        $this->guards->after(DeliberationGuard::SIGN, $principal, $this->repo->deliberation($tenantId, $establishmentId, $id) ?? $raw);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * Réouverture exceptionnelle (directeur académique) : motif obligatoire, signatures effacées, PV précédent archivé
     * (un nouveau PV sera émis à la clôture suivante), journalisée, alerte éventuelle.
     *
     * @return array<string, mixed>
     */
    public function reopen(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->mutable($tenantId, $establishmentId, $id, ['closed']);
        $this->guards->assert(DeliberationGuard::REOPEN, $principal, $row, $reason);
        $history = json_decode((string) ($row['minutes_history'] ?? '[]'), true);
        $history = is_array($history) ? $history : [];
        $history[] = [
            'version' => (int) ($row['minutes_version'] ?? 0), 'document_id' => $row['minutes_document_id'], 'fingerprint' => $row['minutes_fingerprint'] ?? null,
            'signatures' => json_decode((string) ($row['signatures'] ?? '[]'), true) ?: [], 'closed_at' => $row['closed_at'],
            'reopened_at' => $this->clock->now(), 'reopened_by' => $principal->userId, 'reason' => $reason,
        ];
        $this->repo->update('deliberations', $tenantId, $id, [
            'status' => 'open', 'signatures' => '[]', 'reopen_reason' => $reason, 'closed_at' => null,
            'minutes_fingerprint' => null, 'minutes_history' => json_encode($history, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'assessment.deliberation.reopened', 'deliberation', $id, ['status' => 'closed'], ['status' => 'open', 'reason' => $reason]);
        $this->guards->after(DeliberationGuard::REOPEN, $principal, $this->repo->deliberation($tenantId, $establishmentId, $id) ?? $row, $reason);

        return $this->find($tenantId, $establishmentId, $id);
    }

    /**
     * @param list<array<string, mixed>> $rows résultats calculés
     */
    private function fillLines(string $tenantId, string $establishmentId, string $id, string $structureId, string $periodId, string $session, array $rows): void
    {
        $data = $this->results->inputs($tenantId, $establishmentId, $structureId, $periodId, null, $session);
        $credits = [];
        foreach ($data['subjects'] as $subjectId => $subject) {
            if ((int) $subject['credits'] > 0) {
                $credits[$subjectId] = (int) $subject['credits'];
            }
        }
        foreach ($rows as $row) {
            $units = [];
            foreach ((array) json_decode((string) $row['details'], true) as $detail) {
                if (is_array($detail) && isset($credits[(string) $detail['subject_id']])) {
                    $units[] = $detail + ['credits' => $credits[(string) $detail['subject_id']]];
                }
            }
            $this->repo->insert('deliberation_lines', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'deliberation_id' => $id,
                'person_id' => $row['person_id'], 'average' => $row['average'], 'credits_earned' => (int) ($row['credits_earned'] ?? 0),
                'credits_total' => (int) ($row['credits_total'] ?? 0), 'unit_averages' => json_encode($units, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'proposal' => $row['proposal'], 'decision' => $row['proposal'], 'reason' => null,
                'line_extras' => $row['computed_extras'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function rules(array $row): RuleSet
    {
        return $this->results->rulesFor((string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['structure_id'], (string) $row['period_id']);
    }

    /**
     * @param list<string> $statuses
     * @return array<string, mixed>
     */
    private function mutable(string $tenantId, string $establishmentId, string $id, array $statuses): array
    {
        $row = $this->repo->deliberation($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Délibération introuvable.');
        if (!in_array($row['status'], $statuses, true)) {
            throw HttpException::conflict(match ($row['status']) {
                'closed' => 'La délibération est close : ses décisions ne sont plus modifiables. Une réouverture exceptionnelle motivée est possible.',
                'open' => 'La séance est déjà ouverte.',
                default => 'La séance n\'est pas encore ouverte. Enregistrez les présents puis ouvrez la séance.',
            });
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function assertQuorum(array $row): void
    {
        if ((int) $row['quorum_reached'] !== 1) {
            $present = count((array) json_decode((string) $row['members_present'], true));
            throw HttpException::conflict(sprintf('Quorum non atteint : %d membre(s) présent(s) sur %d attendus. Enregistrez les présents avant d\'ouvrir ou de clore la séance.', $present, $row['members_expected']));
        }
    }

    private function hasQuorum(string $tenantId, string $establishmentId, int $present, int $expected): bool
    {
        $percent = $this->settings->quorumPercent($tenantId, $establishmentId);

        return $present > 0 && $present * 100 >= $expected * $percent;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $row
     * @return array<string, int|bool>
     */
    private function summary(array $lines, array $row, RuleSet $rules): array
    {
        $count = static fn (string $label): int => count(array_filter($lines, static fn (array $l): bool => $l['decision'] === $label));

        return [
            'admitted' => $count($rules->decisionLabel('admitted')), 'compensated' => $count($rules->decisionLabel('compensated')), 'resit' => $count($rules->decisionLabel('resit')),
            'adjourned' => $count($rules->decisionLabel('adjourned')), 'modified' => count(array_filter($lines, static fn (array $l): bool => $l['decision'] !== $l['proposal'])),
            'quorum_reached' => (int) $row['quorum_reached'] === 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, int> libellé => nombre de lignes, pour toutes les décisions déclarées
     */
    private function decisionCounts(array $lines, RuleSet $rules): array
    {
        $out = array_fill_keys($rules->decisionLabels(), 0);
        foreach ($lines as $line) {
            if ($line['decision'] !== null) {
                $out[(string) $line['decision']] = ($out[(string) $line['decision']] ?? 0) + 1;
            }
        }

        return $out;
    }

    private function minutesText(string $tenantId, string $establishmentId, string $id): string
    {
        $row = $this->find($tenantId, $establishmentId, $id);
        $out = [
            sprintf('Procès-verbal de délibération · %s · %s%s', $row['structure_id'], $row['period_label'], ($row['exam_session'] ?? 'normal') === AssessmentService::RESIT ? ' · session de rattrapage' : ''),
            sprintf('Président : %s · présents : %d sur %d attendus (quorum atteint)', $row['president_id'], count($row['members_present']), $row['members_expected']),
            '',
        ];
        foreach ($row['lines'] as $line) {
            $modified = $line['decision'] !== $line['proposal'];
            $out[] = sprintf(
                '%s · moyenne %s · crédits %d/%d · décision : %s%s',
                $line['name'] ?? $line['person_id'],
                $line['average'] ?? 'n/a',
                $line['credits_earned'],
                $line['credits_total'],
                $line['decision'] ?? 'aucune',
                $modified ? ' (modifiée par le jury, motif : ' . $line['reason'] . ')' : '',
            );
        }

        return implode("\n", $out);
    }
}
