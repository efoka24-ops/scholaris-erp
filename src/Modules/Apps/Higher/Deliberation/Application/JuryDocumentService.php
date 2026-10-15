<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes as Types;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\MinutesFingerprint;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;

/**
 * Documents du jury : grille de délibération (DOC-14, document de travail), PV de délibération (DOC-15) et liste des résultats à
 * afficher (DOC-16). Les décisions sont celles de la séance du socle, transmises par l'écran ; pour le PV et la liste, elles doivent
 * reproduire exactement l'empreinte SHA-256 enregistrée à la clôture (sinon 409) ; la liste exige la signature du président.
 */
final class JuryDocumentService
{
    public function __construct(
        private readonly JuryService $juries,
        private readonly Access $access,
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly HigherDocumentIssuer $issuer,
    ) {
    }

    /**
     * @param list<array{person_id: string, average: float|null, credits_earned: int, credits_total: int, proposal?: string|null, decision: string|null, reason: string|null}> $lines
     * @return array<string, mixed>
     */
    public function issue(Principal $principal, string $establishmentId, string $juryId, string $code, array $lines, ?int $ruleVersion, ?string $locale): array
    {
        $tenantId = $principal->tenantId;
        $permissions = $code === Types::MINUTES ? [DeliberationPermissions::PRESIDE, DeliberationPermissions::PREPARE] : [DeliberationPermissions::PREPARE];
        $row = $this->juries->readable($principal, $establishmentId, $juryId, $permissions);
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWritable($principal, $perimeter, JuryService::scope($row), 'jury', $juryId);
        if (count($lines) > Types::LINE_SLOTS) {
            throw HttpException::validation(['lines' => [sprintf('Le modèle compte %d lignes au plus : scindez le groupe.', Types::LINE_SLOTS)]]);
        }
        $status = (string) $row['status'];
        if ($code === Types::MINUTES && !in_array($status, ['closed', 'signed', 'published'], true)) {
            throw HttpException::conflict('Le procès-verbal s\'établit après la clôture de la séance.');
        }
        if ($code === Types::RESULTS_LIST && !in_array($status, ['signed', 'published'], true)) {
            throw HttpException::conflict('La liste des résultats s\'affiche après la signature du procès-verbal par le président (RG-DEL-3, RG-DOC-3).');
        }
        if ($code !== Types::GRID) {
            $this->assertFingerprint($row, $lines, $ruleVersion);
        }

        $jury = $this->juries->present($tenantId, $establishmentId, $row);
        $locale = $locale === 'en' ? 'en' : 'fr';
        $names = $this->juries->names($tenantId, array_column($lines, 'person_id'));
        $group = $this->academic->group($tenantId, $establishmentId, (string) $row['structure_id']);
        $common = [
            'group_label' => $group === null ? (string) $row['structure_id'] : $group->name, 'semester_label' => (string) $row['period_label'],
            'session_label' => $row['exam_session'] === 'resit' ? ($locale === 'en' ? 'resit session' : 'session de rattrapage') : ($locale === 'en' ? 'regular session' : 'session normale'),
            'summary_line' => $this->summary($lines, $locale), 'academic_year' => (string) $row['academic_year_id'],
        ];
        $vars = $common;
        foreach ($lines as $i => $line) {
            $vars['l' . Types::slot($i + 1)] = $this->line($tenantId, $establishmentId, $line, $names, $locale, $code);
        }
        if ($code === Types::GRID) {
            $vars['students_count'] = (string) count($lines);
        } elseif ($code === Types::RESULTS_LIST) {
            $vars['jury_ref'] = $juryId;
        } else {
            $members = $jury['members'];
            $vars += [
                'president_name' => (string) ($this->name($members, (string) $row['president_person_id']) ?? $row['president_person_id']),
                'members_line' => implode(', ', array_map(static fn (array $m): string => (string) ($m['name'] ?? $m['person_id']), $members)),
                'present_line' => implode(', ', array_map(static fn (array $m): string => (string) ($m['name'] ?? $m['person_id']), array_filter($members, static fn (array $m): bool => $m['present']))),
                'quorum_line' => sprintf($locale === 'en' ? 'quorum %d of %d members' : 'quorum %d sur %d membres', (int) $row['quorum_min'], count($members)),
                'agenda' => (string) ($row['agenda'] ?? ''), 'minutes_version' => (string) $row['minutes_version'], 'fingerprint' => (string) $row['minutes_fingerprint'],
                'signatures_line' => implode(', ', array_map(fn (array $s): string => ($this->name($members, $s['person_id']) ?? $s['person_id']) . ' (' . $s['signed_at'] . ')', $jury['signatures'])),
            ];
            if (count($jury['jury_points']) > Types::POINT_SLOTS) {
                throw HttpException::validation(['jury_points' => [sprintf('Le modèle liste %d points de jury au plus.', Types::POINT_SLOTS)]]);
            }
            foreach ($jury['jury_points'] as $i => $point) {
                $vars['p' . Types::slot($i + 1)] = sprintf('%s · +%s · %s', $point['name'] ?? $point['person_id'], TranscriptService::num($point['points_granted'], $locale), $point['reason']);
            }
        }
        $issued = $this->issuer->issueFor($tenantId, $establishmentId, $code, (string) $row['president_person_id'], (string) $row['school_id'], (string) $row['program_id'], (string) $row['level_id'], $vars, $principal->userId, $locale);
        $this->access->audit($principal, 'higher.deliberation.document_issued', 'jury', $juryId, null, ['code' => $code, 'number' => $issued['number'] ?? null]);

        return $issued;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{person_id: string, average: float|null, credits_earned: int, credits_total: int, decision: string|null, reason: string|null}> $lines
     */
    private function assertFingerprint(array $row, array $lines, ?int $ruleVersion): void
    {
        if ($row['minutes_fingerprint'] === null || $row['deliberation_ref'] === null || $ruleVersion === null) {
            throw HttpException::conflict('Empreinte du procès-verbal inconnue : la séance doit être close dans la délibération du socle.');
        }
        $normalized = array_map(static fn (array $l): array => [
            'person_id' => $l['person_id'], 'average' => $l['average'], 'credits_earned' => $l['credits_earned'], 'credits_total' => $l['credits_total'],
            'decision' => $l['decision'], 'reason' => $l['reason'],
        ], $lines);
        $fingerprint = MinutesFingerprint::of((string) $row['deliberation_ref'], (string) $row['structure_id'], (string) $row['period_id'], (string) $row['exam_session'], $ruleVersion, $normalized);
        if (!hash_equals((string) $row['minutes_fingerprint'], $fingerprint)) {
            throw HttpException::conflict('Les décisions transmises ne correspondent pas à l\'empreinte SHA-256 du procès-verbal clos : document refusé.');
        }
    }

    /**
     * @param array{person_id: string, average: float|null, credits_earned: int, credits_total: int, proposal?: string|null, decision: string|null, reason: string|null} $line
     * @param array<string, string> $names
     */
    private function line(string $tenantId, string $establishmentId, array $line, array $names, string $locale, string $code): string
    {
        $matricule = $this->students->student($tenantId, $establishmentId, $line['person_id'])?->matricule;
        $who = ($names[$line['person_id']] ?? $line['person_id']) . ($matricule !== null ? ' (' . $matricule . ')' : '');
        if ($code === Types::RESULTS_LIST) {
            return $who . ' · ' . ($line['decision'] ?? '—');
        }
        $text = sprintf(
            $locale === 'en' ? '%s · average %s · %d/%d credits · %s' : '%s · moyenne %s · %d/%d crédits · %s',
            $who,
            TranscriptService::num($line['average'], $locale),
            $line['credits_earned'],
            $line['credits_total'],
            $line['decision'] ?? ($line['proposal'] ?? '—'),
        );
        if (($line['reason'] ?? null) !== null && $line['reason'] !== '') {
            $text .= ($locale === 'en' ? ' (board decision: ' : ' (décision du jury : ') . $line['reason'] . ')';
        }

        return $text;
    }

    /** @param list<array<string, mixed>> $lines */
    private function summary(array $lines, string $locale): string
    {
        $counts = [];
        foreach ($lines as $line) {
            $decision = (string) ($line['decision'] ?? ($line['proposal'] ?? '—'));
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }
        ksort($counts);
        $parts = [];
        foreach ($counts as $decision => $count) {
            $parts[] = $decision . ' : ' . $count;
        }

        return ($locale === 'en' ? count($lines) . ' students · ' : count($lines) . ' étudiants · ') . implode(' · ', $parts);
    }

    /** @param list<array<string, mixed>> $members */
    private function name(array $members, string $personId): ?string
    {
        foreach ($members as $member) {
            if ($member['person_id'] === $personId) {
                return isset($member['name']) ? (string) $member['name'] : null;
            }
        }

        return null;
    }
}
