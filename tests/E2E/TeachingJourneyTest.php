<?php

declare(strict_types=1);

namespace Tests\E2E;

use Scholaris\Core\Support\Ulid;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Parcours 3 (US7, US8, US10) : emploi du temps avec conflit refusé, appel hors ligne synchronisé (rejeu sans doublon)
 * et notification de l'absence au garant, saisie de notes (hors bornes refusée, conflit 409), verrouillage, conseil de classe,
 * publication, lecture des résultats par le parent, exports.
 */
final class TeachingJourneyTest extends RealStackTestCase
{
    use BuildsSchool;

    private string $teacher = '';
    private string $teacherPerson = '';
    /** @var list<string> */
    private array $learners = [];
    /** @var array{person_id: string, login: string, password: string} */
    private array $parent;

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
        $this->parent = $this->provisionUser($this->admin, $this->school, 'Marie', 'Abega', 'parent');
        foreach ([['Ada', 'Abega', 'female'], ['Boris', 'Bello', 'male'], ['Chloé', 'Chi', 'female']] as $i => [$first, $last, $gender]) {
            $guarantor = $i === 0 ? $this->parent['person_id'] : null;
            $this->learners[] = $this->enrollLearner($first, $last, '2011-0' . ($i + 1) . '-10', $guarantor, true, $gender)['learner'];
        }
        $this->ok('POST', '/people/' . $this->learners[0] . '/relations', $this->admin, ['related_person_id' => $this->parent['person_id'], 'kind' => 'parent'], $this->school);
        $account = $this->provisionUser($this->admin, $this->school, 'Tano', 'Prof', 'enseignant');
        $this->teacherPerson = $account['person_id'];
        $this->teacher = $this->signIn($account['login'], $account['password']);
    }

    private function today(): string
    {
        return date('Y-m-d', $this->clock->now());
    }

    public function testEmploiDuTempsAppelHorsLigneEtNotificationDeLAbsence(): void
    {
        $weekday = (int) date('N', $this->clock->now());
        $slot = ['subject_label' => 'Mathématiques', 'teacher_person_id' => $this->teacherPerson, 'structure_ids' => [$this->class], 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-09-01'];
        $slotId = (string) $this->ok('POST', '/timetable-slots', $this->admin, $slot, $this->school, [], 201)['data']['id'];

        // Même enseignant, même heure, autre classe : conflit refusé avec sa cause.
        $other = (string) $this->ok('POST', '/structures', $this->admin, ['kind' => 'class', 'name' => '3e B', 'capacity' => 40], $this->school, [], 201)['data']['id'];
        [$conflict, $problem] = $this->api('POST', '/timetable-slots', $this->admin, ['structure_ids' => [$other], 'start_time' => '09:00', 'end_time' => '11:00'] + $slot, $this->school);
        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame('teacher', $problem['conflicts'][0]['kind']);

        // L'enseignant voit son créneau.
        $timetable = $this->ok('GET', '/timetable?teacher_person_id=' . $this->teacherPerson . '&week=' . $this->today(), $this->teacher, null, $this->school);
        self::assertStringContainsString($slotId, json_encode($timetable, JSON_THROW_ON_ERROR));

        // Appel saisi hors ligne puis synchronisé : Ada présente, Boris absent, Chloé en retard.
        $item = ['client_uuid' => (string) new Ulid(), 'slot_id' => $slotId, 'date' => $this->today(), 'validate' => true, 'records' => [
            ['person_id' => $this->learners[0], 'status' => 'P', 'client_uuid' => (string) new Ulid()],
            ['person_id' => $this->learners[1], 'status' => 'A', 'client_uuid' => (string) new Ulid()],
            ['person_id' => $this->learners[2], 'status' => 'R', 'arrived_at' => '08:12', 'client_uuid' => (string) new Ulid()],
        ]];
        $smsBefore = count($this->sms->sent);
        $synced = $this->ok('POST', '/attendance-sessions/sync', $this->teacher, ['sessions' => [$item]], $this->school);
        self::assertSame('synced', $synced['data'][0]['status'], json_encode($synced));
        $replayed = $this->ok('POST', '/attendance-sessions/sync', $this->teacher, ['sessions' => [$item]], $this->school);
        self::assertTrue($replayed['data'][0]['replayed']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM attendance_sessions')->fetchColumn(), 'Le rejeu ne crée pas de second appel.');
        self::assertSame(3, (int) $this->pdo()->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());

        // L'absence de Boris est notifiée le jour même à son garant (une seule fois malgré le rejeu).
        $notices = $this->pdo()->query('SELECT person_id, status, recipients FROM absence_notifications')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $notices, 'Une notification d\'absence, pas plus : ' . json_encode($notices));
        self::assertSame($this->learners[1], $notices[0]['person_id']);
        self::assertGreaterThanOrEqual(1, (int) $notices[0]['recipients'], json_encode($notices));
        self::assertSame(1, count($this->sms->sent) - $smsBefore, 'Un SMS au garant : ' . json_encode(array_slice($this->sms->sent, $smsBefore)));

        // Une modification tardive d'un appel validé est un conflit signalé, jamais un écrasement silencieux.
        $session = (string) $synced['data'][0]['session']['id'];
        [$late, $lateJson] = $this->api('PUT', '/attendance-sessions/' . $session . '/records', $this->teacher, ['records' => [
            ['person_id' => $this->learners[1], 'status' => 'P', 'client_uuid' => (string) new Ulid()],
        ]], $this->school);
        self::assertSame(409, $late->getStatusCode());
        self::assertArrayHasKey('server_version', $lateJson);

        // Export CSV des présences.
        [$csv] = $this->api('GET', '/attendance-records/export', $this->admin, null, $this->school);
        self::assertSame(200, $csv->getStatusCode(), (string) $csv->getBody());
        self::assertStringStartsWith('text/csv', $csv->getHeaderLine('Content-Type'));
    }

    public function testNotesValidationConseilDeClassePublicationEtLectureParLeParent(): void
    {
        $weekday = (int) date('N', $this->clock->now());
        $this->ok('POST', '/timetable-slots', $this->admin, ['subject_label' => 'Mathématiques', 'teacher_person_id' => $this->teacherPerson, 'structure_ids' => [$this->class], 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-09-01'], $this->school, [], 201);
        $subject = (string) $this->ok('POST', '/subjects', $this->admin, ['code' => 'MATH', 'name' => 'Mathématiques', 'kind' => 'subject', 'coefficient' => 2, 'credits' => 0], $this->school, [], 201)['data']['id'];
        $period = $this->periods[0];
        $assessment = (string) $this->ok('POST', '/assessments', $this->admin, [
            'subject_id' => $subject, 'structure_id' => $this->class, 'period_id' => $period, 'academic_year_id' => $this->year, 'kind' => 'devoir',
            'title' => 'Devoir 1', 'weight' => 1, 'max_score' => 20,
        ], $this->school, [], 201)['data']['id'];

        // Note hors bornes refusée, message explicite.
        [$tooHigh, $problem] = $this->api('POST', '/grades', $this->teacher, ['assessment_id' => $assessment, 'person_id' => $this->learners[0], 'value' => 22], $this->school);
        self::assertSame(422, $tooHigh->getStatusCode(), (string) $tooHigh->getBody());
        self::assertArrayHasKey('trace_id', $problem);

        // Saisie en lot par l'enseignant.
        $batch = $this->ok('POST', '/grades/batch', $this->teacher, ['assessment_id' => $assessment, 'entries' => [
            ['person_id' => $this->learners[0], 'value' => 16], ['person_id' => $this->learners[1], 'value' => 9.5], ['person_id' => $this->learners[2], 'value' => 12],
        ]], $this->school);
        self::assertNotEmpty($batch['data'] ?? $batch);

        // Conflit : une saisie fondée sur une version périmée renvoie 409 avec les deux versions.
        $first = $this->ok('POST', '/grades', $this->teacher, ['assessment_id' => $assessment, 'person_id' => $this->learners[2], 'value' => 13, 'expected_version' => 1], $this->school);
        [$stale, $staleJson] = $this->api('POST', '/grades', $this->teacher, ['assessment_id' => $assessment, 'person_id' => $this->learners[2], 'value' => 14, 'expected_version' => 1], $this->school);
        self::assertSame(409, $stale->getStatusCode(), (string) $stale->getBody() . json_encode($first));
        self::assertStringContainsString('application/problem+json', $stale->getHeaderLine('Content-Type'));

        // Validation de la grille (verrouillage), puis correction directe refusée.
        foreach (['entry', 'locked'] as $to) {
            $this->ok('POST', '/grade-sheets/transition', $this->admin, ['structure_id' => $this->class, 'period_id' => $period, 'to' => $to], $this->school);
        }
        [$locked] = $this->api('POST', '/grades', $this->teacher, ['assessment_id' => $assessment, 'person_id' => $this->learners[0], 'value' => 18], $this->school);
        self::assertContains($locked->getStatusCode(), [403, 409, 422], 'Une note verrouillée ne se modifie pas sans demande approuvée.');

        // Conseil de classe : ouverture, validation (PV et bulletins en file), publication.
        $council = $this->ok('POST', '/class-councils', $this->admin, ['structure_id' => $this->class, 'period_id' => $period, 'period_label' => 'Trimestre 1', 'held_on' => $this->today()], $this->school, [], 201)['data'];
        $this->ok('POST', '/class-councils/' . $council['id'] . '/validate', $this->admin, [], $this->school);
        $cards = $this->ok('POST', '/class-councils/' . $council['id'] . '/report-cards', $this->admin, [], $this->school);
        self::assertNotEmpty($cards);
        $this->ok('POST', '/results/publish', $this->admin, ['structure_id' => $this->class, 'period_id' => $period, 'period_label' => 'Trimestre 1'], $this->school);

        // Résultats : rang et moyenne pour le personnel ; le parent ne voit que son enfant.
        $results = $this->ok('GET', '/results?filter[structure_id]=' . $this->class . '&filter[period_id]=' . $period, $this->admin, null, $this->school);
        $rows = array_column($results['data'], null, 'person_id');
        self::assertEquals(16, $rows[$this->learners[0]]['average']);
        self::assertSame(1, $rows[$this->learners[0]]['rank_in_class']);
        $parentToken = $this->signIn($this->parent['login'], $this->parent['password']);
        $own = $this->ok('GET', '/results', $parentToken, null, $this->school);
        self::assertSame([$this->learners[0]], array_column($own['data'], 'person_id'), 'Le parent ne lit que les résultats de son enfant.');

        // Export xlsx des notes.
        [$xlsx] = $this->api('GET', '/grades/export?format=xlsx&filter[assessment_id]=' . $assessment, $this->admin, null, $this->school);
        self::assertSame(200, $xlsx->getStatusCode(), (string) $xlsx->getBody());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
    }
}
