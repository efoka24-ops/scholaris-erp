<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationContext;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Contracts\GuardRefusal;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Garde de délibération de l'IPES (S8, T284/T329b), enregistrée sous `assessment.deliberation_guards.higher_deliberation` et
 * appliquée aux seules délibérations d'un groupe Higher (les autres applications ne sont pas concernées) :
 *  - ouverture : jury déclaré (composition, président identique) ; toutes les notes verrouillées si une source de notes le dit (RG-DEL-1) ;
 *  - clôture, signature, publication, réouverture : double authentification récente ;
 *  - signature : signataire membre du jury ; empreinte SHA-256 des décisions inchangée depuis la clôture ;
 *  - publication : PV signé par le président (RG-DEL-3, TST-07), ou par tous les présents si `higher.deliberation.signatures_required=all_present` ;
 *  - réouverture : directeur académique (R03) seulement, motif d'au moins 10 caractères (EX-DEL-08).
 * Après chaque étape, le jury Higher est tenu à jour (état, empreinte, version du PV, signatures) ; la publication notifie les
 * étudiants (NOT-12) et enregistre les UE acquises (capitalisation) ; la réouverture est tracée et alerte le directeur général.
 */
final class HigherDeliberationGuard implements DeliberationGuard
{
    public function __construct(
        private readonly Store $store,
        private readonly AcademicDirectory $academic,
        private readonly MarkSources $marks,
        private readonly RecentSecondFactor $secondFactor,
        private readonly UserPersonLink $users,
        private readonly RoleDirectory $roles,
        private readonly Messenger $messenger,
        private readonly StudentDirectory $students,
        private readonly ResultsDirectory $results,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
        private readonly AcademicClock $clock,
    ) {
    }

    public function refusals(DeliberationContext $context): array
    {
        $jury = $this->jury($context);
        if ($jury === null) {
            if ($context->stage === self::OPEN && $this->academic->group($context->tenantId, $context->establishmentId, $context->structureId) !== null) {
                return [GuardRefusal::conflict('Déclarez d\'abord le jury de ce groupe, de cette période et de cette session (composition, président, quorum) dans l\'application Supérieur.')];
            }

            return [];
        }

        return match ($context->stage) {
            self::OPEN => $this->openRefusals($context, $jury),
            self::CLOSE => $this->secondFactor($context),
            self::SIGN => [...$this->secondFactor($context), ...$this->signRefusals($context, $jury), ...$this->fingerprint($context)],
            self::PUBLISH => [...$this->secondFactor($context), ...$this->publishRefusals($context), ...$this->fingerprint($context)],
            self::REOPEN => [...$this->secondFactor($context), ...$this->reopenRefusals($context)],
            default => [],
        };
    }

    public function after(DeliberationContext $context): void
    {
        $jury = $this->jury($context);
        if ($jury === null) {
            return;
        }
        $tenantId = $context->tenantId;
        $establishmentId = $context->establishmentId;
        $id = (string) $jury['id'];
        $now = $this->clock->now();
        $update = fn (array $values) => $this->store->update(JuryService::TABLE, $tenantId, $establishmentId, $values + ['updated_at' => $now], ['id' => $id]);

        switch ($context->stage) {
            case self::OPEN:
                $update(['status' => 'in_session']);
                break;
            case self::CLOSE:
                $update([
                    'status' => 'closed', 'deliberation_ref' => $context->deliberationId, 'minutes_fingerprint' => $context->storedFingerprint ?? $context->currentFingerprint,
                    'minutes_version' => max($context->minutesVersion, (int) $jury['minutes_version'] + 1), 'signed_at' => null,
                ]);
                $this->event($context, $id, 'closed', null);
                break;
            case self::SIGN:
                $this->recordSignatures($context, $jury);
                break;
            case self::PUBLISH:
                $update(['status' => 'published', 'published_at' => $now]);
                $this->event($context, $id, 'published', null);
                $this->publish($context, $jury);
                break;
            case self::REOPEN:
                $update(['status' => 'reopened', 'signed_at' => null, 'published_at' => null, 'reopen_count' => (int) $jury['reopen_count'] + 1, 'deliberation_ref' => $context->deliberationId]);
                $this->event($context, $id, 'reopened', $context->reason);
                $this->alertGeneralDirector($context, $jury);
                break;
        }
    }

    /**
     * @param array<string, mixed> $jury
     * @return list<GuardRefusal>
     */
    private function openRefusals(DeliberationContext $context, array $jury): array
    {
        $out = [];
        if ($context->presidentId !== null && $context->presidentId !== (string) $jury['president_person_id']) {
            $out[] = GuardRefusal::conflict('Le président de la délibération doit être celui du jury déclaré (directeur d\'école).');
        }
        $sheet = $this->marks->sheet($context->tenantId, $context->establishmentId, $context->structureId, $context->periodId, $context->session);
        if ($sheet !== null && $sheet->unlocked !== []) {
            $out[] = GuardRefusal::conflict(sprintf(
                'Toutes les notes des EC doivent être verrouillées avant la délibération (RG-DEL-1) : %d épreuve(s) encore en saisie (%s).',
                count($sheet->unlocked),
                implode(', ', array_slice($sheet->unlocked, 0, 5)),
            ));
        }

        return $out;
    }

    /** @return list<GuardRefusal> */
    private function secondFactor(DeliberationContext $context): array
    {
        return $this->secondFactor->isRecent($context->tenantId, $context->userId)
            ? []
            : [GuardRefusal::forbidden('Opération sensible : confirmez votre double authentification puis recommencez (clôture, signature, publication et réouverture d\'une délibération).')];
    }

    /** @return list<GuardRefusal> */
    private function fingerprint(DeliberationContext $context): array
    {
        if ($context->storedFingerprint !== null && $context->currentFingerprint !== null && $context->storedFingerprint !== $context->currentFingerprint) {
            return [GuardRefusal::conflict('Les décisions ne correspondent plus à l\'empreinte SHA-256 du procès-verbal : rouvrez la délibération pour établir un nouveau procès-verbal.')];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $jury
     * @return list<GuardRefusal>
     */
    private function signRefusals(DeliberationContext $context, array $jury): array
    {
        $person = $this->users->personIdForUser($context->tenantId, $context->userId);
        if ($person === null || $this->store->count('hi_jury_members', $context->tenantId, $context->establishmentId, ['jury_id' => (string) $jury['id'], 'person_id' => $person]) === 0) {
            return [GuardRefusal::forbidden('Seuls le président et les membres du jury signent le procès-verbal.')];
        }

        return [];
    }

    /** @return list<GuardRefusal> */
    private function publishRefusals(DeliberationContext $context): array
    {
        $required = $this->settings->value($context->tenantId, $context->establishmentId, 'higher.deliberation.signatures_required', 'president');
        if ($context->presidentId === null || !$context->hasSigned($context->presidentId)) {
            return [GuardRefusal::conflict('Publication refusée : le procès-verbal doit d\'abord être signé par le président du jury (RG-DEL-3).')];
        }
        if ($required === 'all_present') {
            $missing = array_values(array_filter($context->membersPresent, static fn (string $p): bool => !$context->hasSigned($p)));
            if ($missing !== []) {
                return [GuardRefusal::conflict(sprintf('Publication refusée : %d membre(s) présent(s) n\'ont pas encore signé le procès-verbal.', count($missing)))];
            }
        }

        return [];
    }

    /** @return list<GuardRefusal> */
    private function reopenRefusals(DeliberationContext $context): array
    {
        $out = [];
        $person = $this->users->personIdForUser($context->tenantId, $context->userId);
        $directors = $this->roles->personIdsWithRole($context->tenantId, $context->establishmentId, [DeliberationPermissions::ROLE_ACADEMIC_DIRECTOR]);
        if ($person === null || !in_array($person, $directors, true)) {
            $out[] = GuardRefusal::forbidden('Seul le directeur académique peut rouvrir une délibération signée (EX-DEL-08).');
        }
        if (mb_strlen(trim((string) $context->reason)) < 10) {
            $out[] = new GuardRefusal(422, 'La réouverture est motivée : indiquez un motif d\'au moins 10 caractères.');
        }

        return $out;
    }

    /** @param array<string, mixed> $jury */
    private function recordSignatures(DeliberationContext $context, array $jury): void
    {
        $tenantId = $context->tenantId;
        $establishmentId = $context->establishmentId;
        $id = (string) $jury['id'];
        $version = max(1, $context->minutesVersion, (int) $jury['minutes_version']);
        $known = array_column($this->store->select('hi_minutes_signatures', $tenantId, $establishmentId, ['jury_id' => $id, 'minutes_version' => $version]), 'person_id');
        $roles = [];
        foreach ($this->store->select('hi_jury_members', $tenantId, $establishmentId, ['jury_id' => $id]) as $member) {
            $roles[(string) $member['person_id']] = (string) $member['member_role'];
        }
        foreach ($context->signatures as $signature) {
            if (in_array($signature['person_id'], $known, true)) {
                continue;
            }
            $this->store->insert('hi_minutes_signatures', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'jury_id' => $id, 'minutes_version' => $version, 'person_id' => $signature['person_id'],
                'user_id' => $signature['user_id'], 'member_role' => $roles[$signature['person_id']] ?? 'member',
                'fingerprint' => $context->storedFingerprint ?? $context->currentFingerprint, 'signed_at' => $signature['signed_at'] !== '' ? $signature['signed_at'] : $this->clock->now(),
            ]);
        }
        $values = ['minutes_version' => $version, 'updated_at' => $this->clock->now()];
        if ($context->hasSigned((string) $jury['president_person_id']) && $jury['status'] === 'closed') {
            $values += ['status' => 'signed', 'signed_at' => $this->clock->now()];
        }
        $this->store->update(JuryService::TABLE, $tenantId, $establishmentId, $values, ['id' => $id]);
    }

    /**
     * Publication : NOT-12 aux étudiants du groupe ; UE acquises (validation, compensation) enregistrées pour la capitalisation.
     *
     * @param array<string, mixed> $jury
     */
    private function publish(DeliberationContext $context, array $jury): void
    {
        $tenantId = $context->tenantId;
        $establishmentId = $context->establishmentId;
        $now = $this->clock->now();
        foreach ($this->students->enrolledIn($tenantId, $establishmentId, $context->structureId, (string) $jury['academic_year_id']) as $enrolment) {
            $this->messenger->send($tenantId, $establishmentId, $enrolment->personId, DeliberationPermissions::NOT12, [
                'label' => (string) $jury['period_label'], 'school_year' => (string) $jury['academic_year_id'], 'reference' => (string) ($enrolment->matricule ?? ''),
            ], ['sms', 'push']);
            foreach ($this->results->publishedFor($tenantId, $enrolment->personId) as $result) {
                if ($result->periodId !== $context->periodId || $result->session !== $context->session) {
                    continue;
                }
                foreach ($result->details as $unit) {
                    $status = (string) ($unit['status'] ?? '');
                    if (!in_array($status, [LmdCalculator::ACQUIRED, LmdCalculator::COMPENSATED], true) || !isset($unit['code'])) {
                        continue;
                    }
                    $where = ['person_id' => $enrolment->personId, 'program_id' => (string) $jury['program_id'], 'academic_year_id' => (string) $jury['academic_year_id'], 'unit_code' => (string) $unit['code']];
                    $this->store->delete('hi_unit_credits', $tenantId, $establishmentId, $where);
                    $this->store->insert('hi_unit_credits', $tenantId, $establishmentId, $where + [
                        'id' => (string) new Ulid(), 'level_id' => (string) $jury['level_id'], 'period_id' => $context->periodId,
                        'unit_title' => isset($unit['name']) ? mb_substr((string) $unit['name'], 0, 160) : null, 'credits' => (int) ($unit['credits'] ?? 0),
                        'average' => isset($unit['average']) && is_numeric($unit['average']) ? (float) $unit['average'] : null,
                        'letter' => isset($unit['letter']) ? (string) $unit['letter'] : null, 'unit_status' => $status, 'exam_session' => $context->session,
                        'jury_id' => (string) $jury['id'], 'recorded_at' => $now,
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $jury */
    private function alertGeneralDirector(DeliberationContext $context, array $jury): void
    {
        foreach ($this->roles->personIdsWithRole($context->tenantId, $context->establishmentId, [DeliberationPermissions::ROLE_GENERAL_DIRECTOR]) as $personId) {
            $this->messenger->send($context->tenantId, $context->establishmentId, $personId, DeliberationPermissions::REOPEN_ALERT, [
                'label' => (string) $jury['period_label'], 'details' => mb_substr((string) $context->reason, 0, 300), 'reference' => (string) $jury['id'],
            ], ['email'], true);
        }
    }

    private function event(DeliberationContext $context, string $juryId, string $type, ?string $reason): void
    {
        $this->store->insert('hi_jury_events', $context->tenantId, $context->establishmentId, [
            'id' => (string) new Ulid(), 'jury_id' => $juryId, 'event_type' => $type, 'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'actor_id' => $context->userId, 'payload' => json_encode([
                'deliberation_id' => $context->deliberationId, 'fingerprint' => $context->storedFingerprint ?? $context->currentFingerprint, 'minutes_version' => $context->minutesVersion,
            ], JSON_THROW_ON_ERROR), 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($context->tenantId, $context->userId, 'higher.deliberation.' . $type, 'jury', $juryId, null, ['reason' => $reason, 'deliberation_id' => $context->deliberationId]);
    }

    /** @return array<string, mixed>|null */
    private function jury(DeliberationContext $context): ?array
    {
        return $this->store->select(JuryService::TABLE, $context->tenantId, $context->establishmentId, [
            'structure_id' => $context->structureId, 'period_id' => $context->periodId, 'exam_session' => $context->session,
        ], 'id', 1)[0] ?? null;
    }
}
