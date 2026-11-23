<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Timetable\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Band;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Demand;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\Occupation;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\TimetableDefaults;
use Scholaris\Modules\Apps\Secondary\Timetable\Domain\TimetableGenerator;
use Scholaris\Modules\Apps\Secondary\Timetable\Infrastructure\PdoTeachingAssignments;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/**
 * Génération assistée (T860, EX-EDT-01 à 03) : besoins = affectations actives des classes choisies, volume hebdomadaire lu dans les
 * coefficients de l'établissement (une plage par heure, arrondi au supérieur) ; contraintes = plages de l'établissement,
 * disponibilités (vacataires), créneaux déjà posés et conservés (saisies manuelles, autres classes, autres applications ou
 * établissements de l'enseignant au socle). `apply = false` : simulation sans écriture. `apply = true` : les créneaux générés
 * précédemment pour ces classes sont retirés puis la proposition est posée au socle dans une transaction (tout ou rien).
 */
final class GenerationService
{
    public const RUNS = 'se_timetable_runs';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly TimetableContext $context,
        private readonly AssignmentService $assignments,
        private readonly TimetableService $timetable,
        private readonly EntryWriter $entries,
        private readonly TimetableDirectory $directory,
        private readonly SettingsReader $settings,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body academic_year_id, valid_from, valid_to?, class_ids?, apply?, reason?
     * @return array<string, mixed>
     */
    public function generate(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TimetableContext::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_timetable_run');
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $validFrom = (string) $in->date('valid_from');
        $validTo = $in->date('valid_to', false, true);
        $classIds = $in->references('class_ids', 200, false);
        $apply = (bool) ($in->bool('apply', false) ?? false);
        $reason = $in->string('reason', 255, false, true);
        $in->done();
        $this->context->opened($tenantId, $establishmentId);
        if ($validTo !== null && $validTo < $validFrom) {
            throw HttpException::validation(['valid_to' => ['La fin de validité doit suivre son début.']]);
        }
        $classes = [];
        foreach ($this->context->classesOfYear($tenantId, $establishmentId, $year) as $class) {
            if ($classIds === null || in_array($class->structureId, $classIds, true)) {
                $classes[$class->structureId] = $class;
            }
        }
        if ($classIds !== null && count($classes) !== count($classIds)) {
            throw HttpException::validation(['class_ids' => ['Classe non paramétrée pour cette année dans l\'établissement.']]);
        }
        $bands = [];
        foreach ($this->assignments->bandRows($tenantId, $establishmentId, $year) as $row) {
            $bands[] = new Band((int) $row['weekday'], (string) $row['start_time'], (string) $row['end_time']);
        }
        if ($bands === []) {
            throw HttpException::conflict('Définissez d\'abord les plages horaires de l\'année (aucune durée n\'est fixée à l\'avance).');
        }

        [$demands, $rowsById, $skipped] = $this->demands($tenantId, $establishmentId, $year, $classes);
        $replaced = [];
        foreach ($this->store->select(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, ['academic_year_id' => $year, 'status' => 'active', 'origin' => 'generated'], 'id', 20000) as $row) {
            if (isset($classes[(string) $row['class_id']])) {
                $replaced[] = (string) $row['id'];
            }
        }
        $fixed = $this->fixed($tenantId, $establishmentId, $validFrom, $validTo, $replaced, $demands);
        $availability = [];
        foreach ($demands as $demand) {
            $availability[$demand->teacherId] ??= $this->assignments->windowRows($tenantId, $establishmentId, $year, $demand->teacherId);
        }
        $maxPerDay = $this->maxPerDay($tenantId, $establishmentId);
        $result = (new TimetableGenerator())->generate($bands, $demands, $fixed, $availability, $maxPerDay);

        $placed = [];
        foreach ($result['placed'] as $item) {
            $placed[] = [
                'assignment_id' => $item['demand']->assignmentId, 'class_id' => $item['demand']->classId, 'group_code' => $item['demand']->groupCode,
                'subject_label' => $item['demand']->label, 'teacher_person_id' => $item['demand']->teacherId, 'room_id' => $item['demand']->roomId,
                'weekday' => $item['band']->weekday, 'start_time' => $item['band']->startTime, 'end_time' => $item['band']->endTime,
            ];
        }
        $unplaced = $skipped;
        foreach ($result['unplaced'] as $item) {
            $unplaced[] = ['assignment_id' => $item['demand']->assignmentId, 'class_id' => $item['demand']->classId, 'subject_label' => $item['demand']->label, 'missing_bands' => $item['missing'], 'reason' => 'no_free_band'];
        }
        $runId = (string) new Ulid();
        $now = $this->clock->now();
        $run = [
            'id' => $runId, 'academic_year_id' => $year, 'valid_from' => $validFrom, 'valid_to' => $validTo,
            'class_refs' => json_encode(array_keys($classes), JSON_THROW_ON_ERROR), 'applied' => $apply ? 1 : 0, 'placed_count' => count($placed),
            'unplaced_count' => count($unplaced), 'unplaced_json' => json_encode($unplaced, JSON_THROW_ON_ERROR), 'reason' => $reason,
            'created_by' => $principal->userId, 'created_at' => $now,
        ];
        $created = [];
        if ($apply) {
            $created = $this->store->transaction(function () use ($tenantId, $establishmentId, $replaced, $placed, $rowsById, $validFrom, $validTo, $principal, $runId, $run): array {
                foreach ($replaced as $entryId) {
                    $entry = $this->store->findOrFail(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, $entryId);
                    $this->entries->remove($tenantId, $establishmentId, $entry);
                }
                $ids = [];
                foreach ($placed as $item) {
                    $row = $this->entries->pose($tenantId, $establishmentId, $rowsById[$item['assignment_id']], $item['weekday'], $item['start_time'], $item['end_time'], $validFrom, $validTo, $item['room_id'], 'generated', $principal->userId, $runId);
                    $ids[] = (string) $row['id'];
                }
                $this->store->insert(self::RUNS, $tenantId, $establishmentId, $run);

                return $ids;
            });
            $this->access->audit($principal, 'secondary.timetable.generated', 'se_timetable_run', $runId, ['removed' => count($replaced)], ['placed' => count($placed), 'unplaced' => count($unplaced)]);
        } else {
            $this->store->insert(self::RUNS, $tenantId, $establishmentId, $run);
        }

        return [
            'run_id' => $runId, 'applied' => $apply, 'academic_year_id' => $year, 'valid_from' => $validFrom, 'valid_to' => $validTo,
            'classes' => count($classes), 'placed' => $placed, 'unplaced' => $unplaced, 'removed' => $apply ? count($replaced) : 0,
            'created_entry_ids' => $created, 'max_same_subject_per_day' => $maxPerDay,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function run(array $row): array
    {
        $unplaced = json_decode((string) $row['unplaced_json'], true);
        $classes = json_decode((string) $row['class_refs'], true);

        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'valid_from' => (string) $row['valid_from'],
            'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'], 'class_ids' => is_array($classes) ? $classes : [],
            'applied' => (int) $row['applied'] === 1, 'placed_count' => (int) $row['placed_count'], 'unplaced_count' => (int) $row['unplaced_count'],
            'unplaced' => is_array($unplaced) ? $unplaced : [], 'reason' => $row['reason'], 'created_by' => (string) $row['created_by'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, \Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo> $classes
     * @return array{0: list<Demand>, 1: array<string, array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function demands(string $tenantId, string $establishmentId, string $year, array $classes): array
    {
        $demands = [];
        $rows = [];
        $skipped = [];
        foreach ($this->store->select(PdoTeachingAssignments::ASSIGNMENTS, $tenantId, $establishmentId, ['academic_year_id' => $year, 'status' => 'active'], 'class_id, subject_code, group_code, id', 10000) as $row) {
            $class = $classes[(string) $row['class_id']] ?? null;
            if ($class === null) {
                continue;
            }
            $hours = $this->assignments->weeklyHours($tenantId, $establishmentId, $year, $class->levelCode, $class->streamId, (string) $row['se_subject_id']);
            if ($hours === null || $hours <= 0) {
                $skipped[] = ['assignment_id' => (string) $row['id'], 'class_id' => (string) $row['class_id'], 'subject_label' => (string) $row['subject_label'], 'missing_bands' => 0, 'reason' => 'no_weekly_hours'];
                continue;
            }
            if ($row['room_kind'] === 'workshop') {
                $this->assignments->assertRoomKind($tenantId, $establishmentId, 'workshop');
            }
            $rows[(string) $row['id']] = $row;
            $label = (string) $row['subject_label'] . ($row['group_code'] === null ? '' : ' (' . (string) $row['group_code'] . ')');
            $demands[] = new Demand((string) $row['id'], (string) $row['class_id'], $row['group_code'] === null ? null : (string) $row['group_code'], (string) $row['teacher_person_id'], $this->timetable->defaultRoom($tenantId, $establishmentId, $row), (int) ceil($hours - 1e-9), $label);
        }

        return [$demands, $rows, $skipped];
    }

    /**
     * Créneaux conservés qui valent pendant la période générée : ceux du secondaire non remplacés, et ceux du socle (même
     * établissement ou autre établissement de l'enseignant) qui ne sont pas des créneaux du secondaire.
     *
     * @param list<string> $replaced
     * @param list<Demand> $demands
     * @return list<Occupation>
     */
    private function fixed(string $tenantId, string $establishmentId, string $validFrom, ?string $validTo, array $replaced, array $demands): array
    {
        $fixed = [];
        $ownSlots = [];
        foreach ($this->store->select(PdoTeachingAssignments::ENTRIES, $tenantId, $establishmentId, ['status' => 'active'], 'id', 20000) as $row) {
            $ownSlots[(string) $row['slot_id']] = true;
            if (in_array((string) $row['id'], $replaced, true)) {
                continue;
            }
            if (($row['valid_to'] === null || $row['valid_to'] >= $validFrom) && ($validTo === null || $row['valid_from'] <= $validTo)) {
                $occupation = EntryWriter::occupation($row);
                $fixed[] = new Occupation($occupation->ref, $occupation->weekday, $occupation->startTime, $occupation->endTime, $occupation->teacherId, $occupation->classId, $occupation->groupCode, $occupation->roomId, '', null, $occupation->label);
            }
        }
        $teachers = array_values(array_unique(array_map(static fn (Demand $d): string => $d->teacherId, $demands)));
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $date = TimetableContext::firstWeekday($validFrom, $weekday);
            $slots = $this->directory->slotsOn($tenantId, $establishmentId, $date);
            foreach ($teachers as $teacher) {
                array_push($slots, ...$this->directory->teacherSlotsOn($tenantId, $teacher, $date));
            }
            foreach ($slots as $slot) {
                if (isset($ownSlots[$slot->id])) {
                    continue;
                }
                $fixed[] = new Occupation('socle:' . $slot->id, $slot->weekday, $slot->startTime, $slot->endTime, $slot->teacherPersonId, $slot->establishmentId === $establishmentId ? $slot->structureId : null, null, $slot->establishmentId === $establishmentId ? $slot->roomId : null, '', null, $slot->subjectLabel);
            }
        }

        return $fixed;
    }

    private function maxPerDay(string $tenantId, string $establishmentId): int
    {
        $value = $this->settings->value($tenantId, $establishmentId, TimetableDefaults::MAX_SAME_SUBJECT_PER_DAY_KEY, TimetableDefaults::MAX_SAME_SUBJECT_PER_DAY);

        return is_int($value) && $value > 0 ? $value : TimetableDefaults::MAX_SAME_SUBJECT_PER_DAY;
    }
}
