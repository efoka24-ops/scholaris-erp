<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Timetable;

use Scholaris\Modules\Apps\Secondary\Timetable\Application\ChangeService;
use Scholaris\Modules\Apps\Secondary\Timetable\Application\EntryWriter;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/**
 * T860, T861 : génération sans conflit de 10 classes (volumes de l'établissement, vacataire limité à ses plages), conflit de salle
 * ou d'atelier 409, dédoublement d'atelier seulement avec le module `workshops`, remplacements et permutations notifiés (NOT-14),
 * DOC-07, droits et isolation. Données fictives.
 */
final class TimetableTest extends TimetableWorld
{
    public function testGenerationDeDixClassesSansConflitDansLesDisponibilites(): void
    {
        $w = $this->world(10, ['MATH' => 4, 'FRAN' => 4, 'ANG' => 3, 'HIST' => 2]);
        $school = $w['school'];
        $this->bands($school, self::week());
        $expected = 0;
        foreach ($w['classes'] as $i => $class) {
            $group = intdiv($i, 3);
            foreach (['MATH' => 4, 'FRAN' => 4, 'ANG' => 3, 'HIST' => 2] as $subject => $hours) {
                $extra = $subject === 'HIST' && $group === 0 ? ['teacher_kind' => 'vacataire'] : [];
                $this->assign($school, $class, $w['subjects'][$subject], $this->teacher(strtolower($subject) . '-' . $group), $extra);
                $expected += $hours;
            }
        }
        $windows = [];
        foreach ([3, 5] as $day) {
            $windows[] = ['weekday' => $day, 'start_time' => '07:30', 'end_time' => '11:30', 'availability_kind' => 'available'];
        }
        $this->ok('PUT', '/secondary/timetable/availabilities', ['academic_year_id' => self::YEAR, 'teacher_person_id' => 'hist-0', 'windows' => $windows], self::ADMIN, self::TA, $school);
        $this->ok('PUT', '/secondary/timetable/availabilities', ['academic_year_id' => self::YEAR, 'teacher_person_id' => 'math-1', 'windows' => [['weekday' => 1, 'start_time' => '07:30', 'end_time' => '13:30', 'availability_kind' => 'unavailable']]], self::ADMIN, self::TA, $school);

        $simulation = $this->ok('POST', '/secondary/timetable/generate', ['academic_year_id' => self::YEAR, 'valid_from' => self::FROM], self::ADMIN, self::TA, $school);
        self::assertFalse($simulation['applied']);
        self::assertCount($expected, $simulation['placed']);
        self::assertSame([], $simulation['unplaced']);
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM se_timetable_entries')->fetchColumn(), 'Simulation : rien n\'est écrit.');

        $applied = $this->ok('POST', '/secondary/timetable/generate', ['academic_year_id' => self::YEAR, 'valid_from' => self::FROM, 'apply' => true, 'reason' => 'Rentrée'], self::ADMIN, self::TA, $school);
        self::assertTrue($applied['applied']);
        self::assertCount($expected, $applied['created_entry_ids']);
        self::assertSame(0, $this->ok('GET', '/secondary/timetable/conflicts?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $school)['count']);
        $entries = $this->ok('GET', '/secondary/timetable?academic_year_id=' . self::YEAR, null, self::ADMIN, self::TA, $school)['entries'];
        self::assertCount($expected, $entries);
        $perDay = [];
        foreach ($entries as $entry) {
            if ($entry['teacher_person_id'] === 'hist-0') {
                self::assertContains($entry['weekday'], [3, 5], 'Vacataire placé seulement dans ses plages.');
                self::assertLessThanOrEqual('11:30', $entry['end_time']);
            }
            if ($entry['teacher_person_id'] === 'math-1') {
                self::assertNotSame(1, $entry['weekday'], 'Indisponibilité respectée.');
            }
            $key = $entry['assignment_id'] . '|' . $entry['weekday'];
            $perDay[$key] = ($perDay[$key] ?? 0) + 1;
        }
        self::assertNotSame([], $perDay);
        self::assertLessThanOrEqual(2, max([0, ...array_values($perDay)]), 'Une matière au plus deux heures par jour (valeur de départ à confirmer).');
        $monday = $this->service(TimetableDirectory::class)->slotsOn(self::TA, $school, '2026-10-19');
        self::assertCount(count(array_filter($entries, static fn (array $e): bool => $e['weekday'] === 1)), $monday, 'Créneaux posés au socle.');

        $again = $this->ok('POST', '/secondary/timetable/generate', ['academic_year_id' => self::YEAR, 'valid_from' => self::FROM, 'apply' => true], self::ADMIN, self::TA, $school);
        self::assertSame($expected, $again['removed'], 'Nouvelle génération : les créneaux générés sont remplacés, sans doublon.');
        self::assertSame(0, $this->ok('GET', '/secondary/timetable/conflicts', null, self::ADMIN, self::TA, $school)['count']);
        self::assertCount(2, $this->ok('GET', '/secondary/timetable/runs?filter[applied]=1', null, self::ADMIN, self::TA, $school));
    }

    public function testConflitDeSalleDEnseignantOuDeClasseDonne409(): void
    {
        $w = $this->world(2, ['MATH' => 4, 'PHYS' => 2]);
        $school = $w['school'];
        [$a, $b] = $w['classes'];
        $m1 = $this->assign($school, $a, $w['subjects']['MATH'], $this->teacher('t1'), ['room_kind' => 'lab', 'room_id' => 'labo-1']);
        $m2 = $this->assign($school, $b, $w['subjects']['MATH'], $this->teacher('t2'), ['room_kind' => 'lab', 'room_id' => 'labo-1']);
        $p1 = $this->assign($school, $b, $w['subjects']['PHYS'], 't1');
        $p2 = $this->assign($school, $a, $w['subjects']['PHYS'], $this->teacher('t3'));
        self::assertSame(201, $this->pose($school, $m1['id'], 1, '08:00', '09:00')[0]);
        [$status, $problem] = $this->pose($school, $m2['id'], 1, '08:30', '09:30');
        self::assertSame(409, $status, 'Salle déjà occupée.');
        self::assertSame(EntryWriter::CONFLICT_TYPE, $problem['type']);
        self::assertArrayHasKey('room', $problem['errors']);
        self::assertSame(409, $this->pose($school, $p1['id'], 1, '08:00', '09:00')[0], 'Enseignant déjà en cours.');
        [$status, $problem] = $this->pose($school, $p2['id'], 1, '08:00', '09:00');
        self::assertSame(409, $status);
        self::assertArrayHasKey('class', $problem['errors']);
        $check = $this->ok('POST', '/secondary/timetable/entries/check', ['assignment_id' => $m2['id'], 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'valid_from' => self::FROM], self::ADMIN, self::TA, $school);
        self::assertSame('room', $check['conflicts'][0]['kind']);
        self::assertSame(201, $this->pose($school, $m2['id'], 1, '09:00', '10:00')[0], 'Créneaux bord à bord : aucun conflit.');
        self::assertSame(422, $this->pose($school, $m2['id'], 2, '10:00', '09:00')[0]);
        self::assertSame(1, (int) $this->q("SELECT COUNT(*) FROM se_timetable_entries WHERE assignment_id = '" . $m2['id'] . "'")->fetchColumn(), 'Rien n\'est écrit en cas de conflit.');
    }

    public function testDedoublementDAtelierSeulementAvecLeModuleWorkshops(): void
    {
        $leg = $this->world(1, ['TECH' => 2]);
        [$status, $problem] = $this->call('POST', '/secondary/timetable/assignments', [
            'academic_year_id' => self::YEAR, 'class_id' => $leg['classes'][0], 'subject_ref' => $leg['subjects']['TECH'], 'teacher_person_id' => $this->teacher('t-leg'),
            'teacher_kind' => 'permanent', 'room_kind' => 'workshop', 'group_code' => 'G1',
        ], self::ADMIN, self::TA, $leg['school']);
        self::assertSame(409, $status);
        self::assertSame('module_not_enabled', $problem['code']);

        $w = $this->world(1, ['TECH' => 2, 'MATH' => 2], 'autre', self::TA, [
            'ownership' => 'private_secular', 'cycles' => ['first'], 'teaching_kinds' => ['general'], 'head_title' => 'principal', 'modules' => ['workshops'], 'reason' => 'Ouverture de test',
        ]);
        $school = $w['school'];
        $class = $w['classes'][0];
        $g1 = $this->assign($school, $class, $w['subjects']['TECH'], $this->teacher('chef-a'), ['room_kind' => 'workshop', 'room_id' => 'atelier-1', 'group_code' => 'G1']);
        $g2 = $this->assign($school, $class, $w['subjects']['TECH'], $this->teacher('chef-b'), ['room_kind' => 'workshop', 'room_id' => 'atelier-2', 'group_code' => 'G2']);
        $whole = $this->assign($school, $class, $w['subjects']['MATH'], $this->teacher('t-math'));
        self::assertSame(409, $this->call('POST', '/secondary/timetable/assignments', [
            'academic_year_id' => self::YEAR, 'class_id' => $class, 'subject_ref' => $w['subjects']['TECH'], 'teacher_person_id' => 't-math', 'teacher_kind' => 'permanent', 'room_kind' => 'classroom',
        ], self::ADMIN, self::TA, $school)[0], 'Toute la classe alors que des demi-groupes sont affectés.');
        self::assertSame(201, $this->pose($school, $g1['id'], 2, '08:00', '10:00')[0]);
        self::assertSame(201, $this->pose($school, $g2['id'], 2, '08:00', '10:00')[0], 'Deux demi-groupes en atelier en même temps.');
        [$status, $problem] = $this->pose($school, $whole['id'], 2, '09:00', '10:00');
        self::assertSame(409, $status, 'Toute la classe heurte ses demi-groupes.');
        self::assertArrayHasKey('class', $problem['errors']);
        self::assertSame(2, (int) $this->q("SELECT COUNT(*) FROM se_timetable_groups WHERE class_id = '" . $class . "'")->fetchColumn());
        $doc = $this->ok('GET', '/secondary/timetable/document?view=room&id=atelier-1&on=2026-10-20', null, self::ADMIN, self::TA, $school);
        self::assertSame('DOC-07', $doc['doc']);
        self::assertSame(1, $doc['entries_count']);
        self::assertSame('Mardi', $doc['days'][0]['label']);
    }

    public function testRemplacementEtPermutationNotifiesEtSansConflit(): void
    {
        $w = $this->world(1, ['MATH' => 2, 'FRAN' => 2]);
        $school = $w['school'];
        $class = $w['classes'][0];
        $math = $this->assign($school, $class, $w['subjects']['MATH'], $this->teacher('titulaire'));
        $fran = $this->assign($school, $class, $w['subjects']['FRAN'], $this->teacher('t-fran'));
        $this->teacher('remplacant');
        $e1 = $this->pose($school, $math['id'], 1, '08:00', '09:00')[1]['data'];
        $e2 = $this->pose($school, $fran['id'], 3, '10:00', '11:00')[1]['data'];
        $this->people->add(self::TA, 'eleve-1', 'Awa', 'Fictive');
        $this->people->add(self::TA, 'parent-1', 'Moussa', 'Fictif', '+237600000009');
        $this->people->link(self::TA, 'parent-1', 'eleve-1');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $school, $class, self::YEAR);

        $other = $this->world(1, ['MATH' => 2], 'ces');
        $busy = $this->assign($other['school'], $other['classes'][0], $other['subjects']['MATH'], 'remplacant');
        self::assertSame(201, $this->call('POST', '/secondary/timetable/entries', ['assignment_id' => $busy['id'], 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'valid_from' => self::FROM], self::ADMIN, self::TA, $other['school'])[0]);
        $body = ['change_kind' => 'replacement', 'entry_id' => $e1['id'], 'from_date' => '2026-10-26', 'to_date' => '2026-11-08', 'replacement_person_id' => 'remplacant', 'reason' => 'Congé du titulaire'];
        [$status] = $this->call('POST', '/secondary/timetable/changes', $body, self::ADMIN, self::TA, $school);
        self::assertSame(409, $status, 'Le remplaçant a cours ailleurs au même moment (socle).');
        self::assertSame(1, (int) $this->q("SELECT COUNT(*) FROM se_timetable_entries WHERE assignment_id = '" . $math['id'] . "'")->fetchColumn(), 'Transaction : rien n\'est écrit.');
        self::assertSame([], $this->messenger->of(ChangeService::TEMPLATE_NOT14));

        $this->ok('DELETE', '/secondary/timetable/entries/' . $this->q("SELECT id FROM se_timetable_entries WHERE assignment_id = '" . $busy['id'] . "'")->fetchColumn(), null, self::ADMIN, self::TA, $other['school']);
        $change = $this->ok('POST', '/secondary/timetable/changes', $body, self::ADMIN, self::TA, $school);
        self::assertSame(2, $change['notified_count'], 'Élève et garant.');
        self::assertCount(2, $change['created_entry_ids'], 'Créneau du remplaçant puis reprise du titulaire.');
        $sent = $this->messenger->of(ChangeService::TEMPLATE_NOT14);
        self::assertSame(['eleve-1', 'parent-1'], array_column($sent, 'person'));
        self::assertSame('6e A', $sent[0]['variables']['class_name']);
        $entries = $this->ok('GET', '/secondary/timetable?class_id=' . $class . '&on=2026-10-26', null, self::ADMIN, self::TA, $school)['entries'];
        $teachers = array_column($entries, 'teacher_person_id', 'subject_label');
        self::assertSame('remplacant', $teachers['Matière MATH']);
        $later = $this->ok('GET', '/secondary/timetable?class_id=' . $class . '&on=2026-11-09', null, self::ADMIN, self::TA, $school)['entries'];
        self::assertSame('titulaire', array_column($later, 'teacher_person_id', 'subject_label')['Matière MATH'], 'Le titulaire reprend.');
        $before = $this->ok('GET', '/secondary/timetable?class_id=' . $class . '&on=2026-10-19', null, self::ADMIN, self::TA, $school)['entries'];
        self::assertSame('titulaire', array_column($before, 'teacher_person_id', 'subject_label')['Matière MATH'], 'Le passé n\'est pas réécrit.');

        $permutation = $this->ok('POST', '/secondary/timetable/changes', ['change_kind' => 'permutation', 'entry_id' => $e2['id'], 'other_entry_id' => $this->entryOn($class, 'Matière MATH', '2026-11-16'), 'from_date' => '2026-11-16', 'reason' => 'Sortie pédagogique'], self::ADMIN, self::TA, $school);
        self::assertSame('permutation', $permutation['change_kind']);
        $after = $this->ok('GET', '/secondary/timetable?class_id=' . $class . '&on=2026-11-16', null, self::ADMIN, self::TA, $school)['entries'];
        $slots = [];
        foreach ($after as $entry) {
            $slots[$entry['subject_label']] = $entry['weekday'] . ' ' . $entry['start_time'];
        }
        ksort($slots);
        self::assertSame(['Matière FRAN' => '1 08:00', 'Matière MATH' => '3 10:00'], $slots);
        self::assertSame(0, $this->ok('GET', '/secondary/timetable/conflicts', null, self::ADMIN, self::TA, $school)['count']);
        self::assertCount(2, $this->ok('GET', '/secondary/timetable/changes', null, self::ADMIN, self::TA, $school));
        self::assertSame(422, $this->call('POST', '/secondary/timetable/changes', ['change_kind' => 'replacement', 'entry_id' => $e2['id'], 'from_date' => self::FROM, 'replacement_person_id' => 'remplacant', 'reason' => 'x'], self::ADMIN, self::TA, $school)[0], 'Date d\'effet au début du créneau.');
    }

    public function testDroitsDeLEnseignantEtIsolation(): void
    {
        $w = $this->world(2, ['MATH' => 2]);
        $school = $w['school'];
        [$a, $b] = $w['classes'];
        $mine = $this->assign($school, $a, $w['subjects']['MATH'], $this->teacher('prof-1'));
        $theirs = $this->assign($school, $b, $w['subjects']['MATH'], $this->teacher('prof-2'));
        $this->pose($school, $mine['id'], 1, '08:00', '09:00');
        $this->pose($school, $theirs['id'], 2, '08:00', '09:00');
        $this->grant(self::TA, 'u-prof-1', $school, ['secondary.lesson.log'], Scope::CLASS_GROUP, $a);
        $this->link(self::TA, 'u-prof-1', 'prof-1');
        $own = $this->ok('GET', '/secondary/timetable', null, 'u-prof-1', self::TA, $school)['entries'];
        self::assertSame(['prof-1'], array_values(array_unique(array_column($own, 'teacher_person_id'))), 'L\'enseignant ne voit que son emploi du temps.');
        self::assertSame(403, $this->call('POST', '/secondary/timetable/assignments', ['academic_year_id' => self::YEAR], 'u-prof-1', self::TA, $school)[0]);
        self::assertSame(403, $this->call('POST', '/secondary/timetable/generate', [], 'u-prof-1', self::TA, $school)[0]);

        $this->grant(self::TA, 'censeur-a', $school, ['secondary.timetable.manage'], Scope::CLASS_GROUP, $a);
        self::assertSame(403, $this->call('POST', '/secondary/timetable/assignments', ['academic_year_id' => self::YEAR, 'class_id' => $b, 'subject_ref' => $w['subjects']['MATH'], 'teacher_person_id' => 'prof-1', 'teacher_kind' => 'permanent', 'room_kind' => 'classroom'], 'censeur-a', self::TA, $school)[0], 'Classe hors périmètre.');
        self::assertSame(403, $this->call('POST', '/secondary/timetable/generate', ['academic_year_id' => self::YEAR, 'valid_from' => self::FROM], 'censeur-a', self::TA, $school)[0], 'La génération exige tout l\'établissement.');

        $schoolB = $this->factory->opened(self::TB, 'leg');
        $this->grant(self::TB, 'admin-b', $schoolB, self::CENSOR);
        self::assertSame([], $this->ok('GET', '/secondary/timetable', null, 'admin-b', self::TB, $schoolB)['entries']);
        self::assertSame(404, $this->call('PATCH', '/secondary/timetable/assignments/' . $mine['id'], ['status' => 'ended'], 'admin-b', self::TB, $schoolB)[0]);
        self::assertSame(404, $this->call('DELETE', '/secondary/timetable/entries/' . $this->entryOn($a, 'Matière MATH', '2026-10-19'), null, 'admin-b', self::TB, $schoolB)[0]);

        $contract = $this->service(TeachingAssignments::class);
        self::assertCount(1, $contract->forTeacher(self::TA, $school, 'prof-1', self::YEAR));
        self::assertSame([], $contract->forTeacher(self::TB, $school, 'prof-1'));
        self::assertCount(1, $contract->entriesOf(self::TA, $school, $mine['id']));
    }

    private function entryOn(string $class, string $label, string $date): string
    {
        $statement = $this->pdo()->prepare("SELECT id FROM se_timetable_entries WHERE class_id = ? AND subject_label = ? AND status = 'active' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)");
        $statement->execute([$class, $label, $date, $date]);

        return (string) $statement->fetchColumn();
    }
}
