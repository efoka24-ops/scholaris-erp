<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Remplacements et permutations (T861, EX-EDT-04) à partir d'une date, jusqu'à une date ou sans fin :
 *  - remplacement : un autre enseignant tient le créneau ; le créneau d'origine s'arrête la veille, un créneau du remplaçant est
 *    posé, puis le créneau d'origine reprend après la date de fin ;
 *  - permutation : deux créneaux de la même classe échangent leurs horaires pendant la période.
 * Le passé n'est jamais réécrit (le créneau s'arrête la veille) ; tout conflit (remplaçant déjà en cours, salle prise) donne 409 et
 * rien n'est écrit (transaction). Chaque changement est notifié aux élèves de la classe et à leurs garants (NOT-14, modèle
 * `sec.not14.timetable_changed`), une seule fois par destinataire ; un échec d'envoi n'annule pas le changement.
 */
final class ChangeService
{
    public const CHANGES = 'se_timetable_changes';
    public const NOTICES = 'se_timetable_notices';
    public const KINDS = ['replacement', 'permutation'];
    public const TEMPLATE_NOT14 = 'sec.not14.timetable_changed';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly TimetableContext $context,
        private readonly EntryWriter $entries,
        private readonly Messenger $messenger,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $body change_kind, entry_id, from_date, to_date?, reason, replacement_person_id (remplacement), other_entry_id (permutation)
     * @return array<string, mixed>
     */
    public function change(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $in = new Input($body);
        $kind = (string) $in->enum('change_kind', self::KINDS);
        $entryId = (string) $in->reference('entry_id');
        $from = (string) $in->date('from_date');
        $to = $in->date('to_date', false, true);
        $reason = (string) $in->string('reason', 255);
        $replacement = $in->reference('replacement_person_id', false, true);
        $otherId = $in->reference('other_entry_id', false, true);
        $in->done();
        $entry = $this->activeEntry($tenantId, $establishmentId, $entryId, 'entry_id');
        $this->access->assertWritableClass($principal, $perimeter, (string) $entry['class_id'], 'se_timetable_entry', $entryId);
        $this->context->opened($tenantId, $establishmentId);
        $errors = [];
        if ($to !== null && $to < $from) {
            $errors['to_date'] = ['La date de fin doit suivre la date de début.'];
        }
        if ($kind === 'replacement' && ($replacement === null || $replacement === $entry['teacher_person_id'])) {
            $errors['replacement_person_id'] = ['Enseignant remplaçant (autre que le titulaire) attendu.'];
        }
        if ($kind === 'replacement' && $replacement !== null && !$this->context->personExists($tenantId, $replacement)) {
            $errors['replacement_person_id'] = ['Enseignant introuvable.'];
        }
        $other = null;
        if ($kind === 'permutation') {
            if ($otherId === null || $otherId === $entryId) {
                $errors['other_entry_id'] = ['Second créneau de la même classe attendu.'];
            } else {
                $other = $this->activeEntry($tenantId, $establishmentId, $otherId, 'other_entry_id');
                if ($other['class_id'] !== $entry['class_id']) {
                    $errors['other_entry_id'] = ['Une permutation se fait entre deux créneaux de la même classe.'];
                }
            }
        }
        foreach (array_filter([$entry, $other]) as $target) {
            if ($from <= $target['valid_from'] || ($target['valid_to'] !== null && $from > $target['valid_to'])) {
                $errors['from_date'] = ['La date d\'effet doit suivre le début de validité du créneau et rester dans sa période (sinon modifiez le créneau).'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $changeId = (string) new Ulid();
        $created = $this->store->transaction(function () use ($tenantId, $establishmentId, $kind, $entry, $other, $from, $to, $replacement, $principal): array {
            if ($kind === 'replacement') {
                return $this->split($tenantId, $establishmentId, $entry, $from, $to, $principal->userId, null, $replacement);
            }
            /** @var array<string, mixed> $other */
            $this->entries->endBefore($tenantId, $establishmentId, $entry, $from);
            $this->entries->endBefore($tenantId, $establishmentId, $other, $from);
            $ids = [];
            foreach ([[$entry, $other], [$other, $entry]] as [$moved, $place]) {
                $assignment = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, (string) $moved['assignment_id']);
                $row = $this->entries->pose($tenantId, $establishmentId, $assignment, (int) $place['weekday'], (string) $place['start_time'], (string) $place['end_time'], $from, self::until($to, $moved), $moved['room_id'] === null ? null : (string) $moved['room_id'], 'permutation', $principal->userId, null, (string) $moved['id'], (string) $moved['teacher_person_id']);
                $ids[] = (string) $row['id'];
            }
            foreach ([$entry, $other] as $original) {
                $ids = [...$ids, ...$this->resume($tenantId, $establishmentId, $original, $to, $principal->userId)];
            }

            return $ids;
        });
        $labels = [(string) $entry['subject_label']];
        if ($other !== null) {
            $labels[] = (string) $other['subject_label'];
        }
        $row = [
            'id' => $changeId, 'academic_year_id' => (string) $entry['academic_year_id'], 'change_kind' => $kind, 'entry_id' => $entryId, 'other_entry_id' => $other === null ? null : (string) $other['id'],
            'class_id' => (string) $entry['class_id'], 'from_date' => $from, 'to_date' => $to, 'replacement_person_id' => $replacement,
            'previous_person_id' => (string) $entry['teacher_person_id'], 'created_entry_refs' => json_encode($created, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'notified_count' => 0, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ];
        $this->store->insert(self::CHANGES, $tenantId, $establishmentId, $row);
        $label = $kind === 'replacement' ? 'remplacement de l\'enseignant de ' . $labels[0] : 'permutation ' . implode(' / ', $labels);
        $row['notified_count'] = $this->notify($tenantId, $establishmentId, $changeId, (string) $entry['class_id'], $from, $label);
        $this->store->update(self::CHANGES, $tenantId, $establishmentId, ['notified_count' => $row['notified_count']], ['id' => $changeId]);
        $this->access->audit($principal, 'secondary.timetable.changed', 'se_timetable_entry', $entryId, null, $row);

        return self::resource($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $refs = json_decode((string) $row['created_entry_refs'], true);

        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'change_kind' => (string) $row['change_kind'],
            'entry_id' => (string) $row['entry_id'], 'other_entry_id' => $row['other_entry_id'] === null ? null : (string) $row['other_entry_id'],
            'class_id' => (string) $row['class_id'], 'from_date' => (string) $row['from_date'], 'to_date' => $row['to_date'] === null ? null : (string) $row['to_date'],
            'replacement_person_id' => $row['replacement_person_id'] === null ? null : (string) $row['replacement_person_id'],
            'previous_person_id' => $row['previous_person_id'] === null ? null : (string) $row['previous_person_id'], 'created_entry_ids' => is_array($refs) ? $refs : [],
            'reason' => (string) $row['reason'], 'notified_count' => (int) $row['notified_count'], 'created_by' => (string) $row['created_by'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Remplacement : arrêt la veille, créneau du remplaçant sur la période, reprise du titulaire ensuite.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function split(string $tenantId, string $establishmentId, array $entry, string $from, ?string $to, string $actorId, ?string $runId, ?string $teacher): array
    {
        $this->entries->endBefore($tenantId, $establishmentId, $entry, $from);
        $assignment = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, (string) $entry['assignment_id']);
        $row = $this->entries->pose($tenantId, $establishmentId, $assignment, (int) $entry['weekday'], (string) $entry['start_time'], (string) $entry['end_time'], $from, self::until($to, $entry), $entry['room_id'] === null ? null : (string) $entry['room_id'], 'replacement', $actorId, $runId, (string) $entry['id'], $teacher);

        return [(string) $row['id'], ...$this->resume($tenantId, $establishmentId, $entry, $to, $actorId)];
    }

    /**
     * Reprise du créneau d'origine le lendemain de la fin du changement, s'il valait encore après.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function resume(string $tenantId, string $establishmentId, array $entry, ?string $to, string $actorId): array
    {
        if ($to === null || ($entry['valid_to'] !== null && $to >= $entry['valid_to'])) {
            return [];
        }
        $assignment = $this->store->findOrFail(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, (string) $entry['assignment_id']);
        $row = $this->entries->pose($tenantId, $establishmentId, $assignment, (int) $entry['weekday'], (string) $entry['start_time'], (string) $entry['end_time'], TimetableContext::dayAfter($to), $entry['valid_to'] === null ? null : (string) $entry['valid_to'], $entry['room_id'] === null ? null : (string) $entry['room_id'], (string) $entry['origin'], $actorId, null, (string) $entry['id'], (string) $entry['teacher_person_id']);

        return [(string) $row['id']];
    }

    /** @param array<string, mixed> $entry */
    private static function until(?string $to, array $entry): ?string
    {
        $end = $entry['valid_to'] === null ? null : (string) $entry['valid_to'];
        if ($to === null) {
            return $end;
        }

        return $end === null || $to < $end ? $to : $end;
    }

    /** @return array<string, mixed> */
    private function activeEntry(string $tenantId, string $establishmentId, string $id, string $field): array
    {
        $entry = $this->store->find(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $id);
        if ($entry === null || $entry['status'] !== 'active') {
            throw HttpException::notFound('Créneau introuvable (' . $field . ').');
        }

        return $entry;
    }

    /** NOT-14 : élèves inscrits dans la classe et leurs garants, une fois par changement et destinataire. */
    private function notify(string $tenantId, string $establishmentId, string $changeId, string $classId, string $from, string $label): int
    {
        $recipients = [];
        foreach ($this->enrollments->personIdsInStructure($tenantId, $classId) as $learnerId) {
            $recipients[$learnerId] = true;
            foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
                $recipients[$guardian->id] = true;
            }
        }
        $variables = ['class_name' => $this->context->className($tenantId, $establishmentId, $classId), 'date' => $from, 'change_label' => mb_substr($label, 0, 160)];
        $sent = 0;
        foreach (array_keys($recipients) as $personId) {
            $personId = (string) $personId;
            if ($this->store->count(self::NOTICES, $tenantId, $establishmentId, ['change_id' => $changeId, 'recipient_person_id' => $personId]) > 0) {
                continue;
            }
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE_NOT14, $variables, ['push']);
            } catch (Throwable $e) {
                $this->logger->warning('Notification NOT-14 non envoyée : ' . $e->getMessage(), ['change' => $changeId]);
                continue;
            }
            $this->store->insert(self::NOTICES, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'change_id' => $changeId, 'recipient_person_id' => $personId, 'template_key' => self::TEMPLATE_NOT14,
                'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
            ]);
            $result->status !== DeliveryResult::BLOCKED && ++$sent;
        }

        return $sent;
    }
}
