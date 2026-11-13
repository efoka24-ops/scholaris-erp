<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ClassLife\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Cahier de liaison de la classe (EX-PRI-38) : leçons, devoirs et mots aux familles saisis par le maître, publiés aux parents des élèves
 * de la classe SEULEMENT. La publication prévient les parents (NOT-PRI-05, Messenger : consentement et plages horaires respectés).
 * Pas de photo (Q19 : texte seul, défaut à confirmer). Tout est borné au périmètre classe du maître.
 */
final class JournalService
{
    public const TABLE = 'pri_class_journal';
    public const KINDS = ['lesson', 'homework', 'liaison'];
    public const READ = ['primary.journal.read', 'primary.journal.write'];
    public const WRITE = ['primary.journal.write'];
    public const TEMPLATE = 'pri.not05.logbook_entry';
    public const LINK = '/portail/famille';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(Principal $principal, string $establishmentId, string $classId, ?string $from, ?string $to): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $this->classes->resolve($principal->tenantId, $establishmentId, $classId);
        $rows = $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['class_structure_id' => $classId], 'entry_date DESC, id', 500);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => ($from === null || (string) $r['entry_date'] >= $from) && ($to === null || (string) $r['entry_date'] <= $to)));
        $seen = $this->seenCounts($principal->tenantId, $establishmentId, array_map(static fn (array $r): string => (string) $r['id'], $rows));

        return array_map(fn (array $r): array => self::resource($r) + ['seen_count' => $seen[(string) $r['id']] ?? 0], $rows);
    }

    /**
     * @param array<string, mixed> $input entry_date, entry_kind, title, body, subject_id, visible_to_parents
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, string $classId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_class_journal', $classId);
        $this->classes->resolve($principal->tenantId, $establishmentId, $classId);
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'class_structure_id' => $classId, 'entry_date' => (string) ($input['entry_date'] ?? $this->clock->today()),
            'entry_kind' => (string) ($input['entry_kind'] ?? 'liaison'), 'subject_id' => isset($input['subject_id']) ? (string) $input['subject_id'] : null,
            'author_person_id' => null, 'author_user_id' => $principal->userId, 'title' => (string) $input['title'], 'body' => (string) $input['body'],
            'visible_to_parents' => (bool) ($input['visible_to_parents'] ?? true) ? 1 : 0, 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.journal.created', 'pri_class_journal', $id, null, ['class_structure_id' => $classId]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $entryId, array $changes): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $entryId, 'Entrée introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $row['class_structure_id'], 'pri_class_journal', $entryId);
        if ($row['published_at'] !== null) {
            throw HttpException::conflict('Une entrée publiée aux familles ne se modifie plus : ajoutez une nouvelle entrée.');
        }
        $values = $changes;
        if (array_key_exists('visible_to_parents', $values)) {
            $values['visible_to_parents'] = $values['visible_to_parents'] ? 1 : 0;
        }
        $values['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => $entryId]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $entryId));
    }

    /** @return array<string, mixed> */
    public function publish(Principal $principal, string $establishmentId, string $entryId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::TABLE, $t, $establishmentId, $entryId, 'Entrée introuvable.');
        $classId = (string) $row['class_structure_id'];
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_class_journal', $entryId);
        if ($row['published_at'] !== null) {
            throw HttpException::conflict('Cette entrée est déjà publiée.');
        }
        $now = $this->clock->now();
        $this->store->update(self::TABLE, $t, $establishmentId, ['published_at' => $now, 'updated_at' => $now], ['id' => $entryId]);
        $notified = 0;
        if ((int) $row['visible_to_parents'] === 1) {
            $context = $this->classes->resolve($t, $establishmentId, $classId);
            $guardians = [];
            foreach ($context['learner_ids'] as $learnerId) {
                foreach ($this->people->guarantorsOf($t, $learnerId) as $guardian) {
                    $guardians[$guardian->id] = true;
                }
            }
            foreach (array_keys($guardians) as $guardianId) {
                $result = $this->messenger->send($t, $establishmentId, $guardianId, self::TEMPLATE, ['class_name' => $context['class']->name, 'date' => (string) $row['entry_date'], 'link' => self::LINK]);
                $notified += $result->status === DeliveryResult::BLOCKED ? 0 : 1;
            }
        }
        $this->access->audit($principal, 'primary.journal.published', 'pri_class_journal', $entryId, null, ['notified' => $notified]);

        return self::resource($this->store->findOrFail(self::TABLE, $t, $establishmentId, $entryId)) + ['notified' => $notified];
    }

    /**
     * @param list<string> $entryIds
     * @return array<string, int>
     */
    private function seenCounts(string $tenantId, string $establishmentId, array $entryIds): array
    {
        $counts = [];
        foreach ($this->store->select('pri_journal_receipts', $tenantId, $establishmentId, [], 'entry_id', 20000) as $receipt) {
            $counts[(string) $receipt['entry_id']] = ($counts[(string) $receipt['entry_id']] ?? 0) + 1;
        }

        return array_intersect_key($counts, array_flip($entryIds));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'class_structure_id' => (string) $row['class_structure_id'], 'entry_date' => (string) $row['entry_date'],
            'entry_kind' => (string) $row['entry_kind'], 'subject_id' => $row['subject_id'] === null ? null : (string) $row['subject_id'],
            'title' => (string) $row['title'], 'body' => (string) $row['body'], 'visible_to_parents' => (int) $row['visible_to_parents'] === 1,
            'published_at' => $row['published_at'] === null ? null : (string) $row['published_at'],
        ];
    }
}
