<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use Scholaris\Core\Support\Ulid;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Enregistre, depuis la PILE RÉELLE, les réponses de l'API qu'enchaînent les écrans testés par Playwright (connexion,
 * accueil, appel, notes, portail famille, administration, exports) : web/e2e/fixtures/recorded.json. Le faux backend de
 * Playwright sert ces corps : ses réponses ont la forme exacte de l'API. Vérifie au passage que chaque appel du front,
 * tel que l'enchaîne l'écran, aboutit sur la vraie API avec le rôle concerné.
 *
 *   RECORD_UI_FIXTURES=1 php vendor/bin/phpunit tests/E2E/UiFixturesRecorderTest.php
 */
#[Group('enregistrement')]
final class UiFixturesRecorderTest extends RealStackTestCase
{
    use BuildsSchool;

    /** @var array<string, array{status: int, content_type: string, body: mixed}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->bootRealStack();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array<int, mixed>
     */
    private function record(string $name, string $method, string $path, ?string $token, ?array $body = null, ?string $establishment = null, array $headers = []): array
    {
        [$response, $json] = $this->api($method, $path, $token, $body, $establishment, $headers);
        $type = $response->getHeaderLine('Content-Type');
        $raw = (string) $response->getBody();
        $this->recorded[$name] = [
            'request' => $method . ' ' . $path,
            'status' => $response->getStatusCode(),
            'content_type' => $type,
            'body' => str_contains($type, 'json') ? $json : (str_starts_with($type, 'text/') ? $raw : base64_encode($raw)),
        ];

        return [$response->getStatusCode(), $json];
    }

    public function testEnregistreLesReponsesDesEcransPrincipaux(): void
    {
        $this->buildSchool('Groupe scolaire Démo', 'Collège Bilingue Démo');
        $teacher = $this->provisionUser($this->admin, $this->school, 'Thérèse', 'Mbarga', 'enseignant');
        $parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        $learners = [];
        foreach ([['Nadia', 'Foka', 'female', $parent['person_id']], ['Yann', 'Ewane', 'male', null], ['Chloé', 'Ngo', 'female', null]] as $i => [$first, $last, $gender, $guarantor]) {
            $learners[] = $this->enrollLearner($first, $last, '2011-0' . ($i + 1) . '-12', $guarantor, true, $gender)['learner'];
        }
        $this->ok('POST', '/people/' . $learners[0] . '/relations', $this->admin, ['related_person_id' => $parent['person_id'], 'kind' => 'parent'], $this->school);
        $this->ok('POST', '/staff', $this->admin, ['person_id' => $teacher['person_id'], 'kind' => 'permanent'], $this->school, [], 201);
        $weekday = (int) date('N', $this->clock->now());
        $slot = (string) $this->ok('POST', '/timetable-slots', $this->admin, ['subject_label' => 'Mathématiques', 'teacher_person_id' => $teacher['person_id'], 'structure_ids' => [$this->class], 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '10:00', 'valid_from' => '2026-09-01'], $this->school, [], 201)['data']['id'];
        $subject = (string) $this->ok('POST', '/subjects', $this->admin, ['code' => 'MATH', 'name' => 'Mathématiques', 'kind' => 'subject', 'coefficient' => 2, 'credits' => 0], $this->school, [], 201)['data']['id'];
        $assessment = (string) $this->ok('POST', '/assessments', $this->admin, ['subject_id' => $subject, 'structure_id' => $this->class, 'period_id' => $this->periods[0], 'academic_year_id' => $this->year, 'kind' => 'devoir', 'title' => 'Devoir 1', 'weight' => 1, 'max_score' => 20], $this->school, [], 201)['data']['id'];
        $item = (string) $this->ok('POST', '/fee-items', $this->admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 300000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $this->admin, ['fee_item_id' => $item, 'academic_year_id' => $this->year, 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 150000], ['due_on' => '2027-01-15', 'amount' => 150000]]], $this->school, [], 201);
        $enrollment = (string) $this->ok('GET', '/enrollments?filter[person_id]=' . $learners[0], $this->admin, null, $this->school)['data'][0]['id'];
        $this->ok('POST', '/invoices', $this->admin, ['enrollment_id' => $enrollment], $this->school, [], 201);
        $today = date('Y-m-d', $this->clock->now());

        // Administrateur (super administrateur de client).
        $admin = $this->admin;
        $this->record('admin.me', 'GET', '/me', $admin);
        $this->record('admin.contexts', 'GET', '/me/contexts', $admin);
        $this->record('admin.applications', 'GET', '/me/applications', $admin);
        $this->record('admin.context', 'POST', '/me/context', $admin, ['establishment_id' => $this->school]);
        $this->record('admin.establishments', 'GET', '/establishments?per_page=1', $admin);
        $this->record('admin.establishments.all', 'GET', '/establishments', $admin);
        $this->record('admin.establishment.created', 'POST', '/establishments', $admin, ['name' => 'École Primaire Démo', 'type' => 'primary']);
        $this->record('admin.todo', 'GET', '/me/todo', $admin);
        $this->record('admin.vocabulary', 'GET', '/vocabulary', $admin);
        $this->record('admin.people', 'GET', '/people', $admin, null, $this->school);
        $this->record('admin.people.csv', 'GET', '/people/export?format=csv', $admin, null, $this->school);
        $this->record('admin.people.xlsx', 'GET', '/people/export?format=xlsx', $admin, null, $this->school);
        $this->record('admin.structures', 'GET', '/structures', $admin, null, $this->school);
        $this->record('admin.periods', 'GET', '/periods', $admin, null, $this->school);
        $this->record('admin.years', 'GET', '/academic-years', $admin, null, $this->school);
        $this->record('login.bad', 'POST', '/auth/login', null, ['identifier' => '+237699000001', 'password' => 'mauvais-mot-de-passe']);

        // Enseignant : appel et notes, enchaînés comme dans web/src/offline/*.ts.
        $t = $this->signIn($teacher['login'], $teacher['password']);
        $this->record('teacher.me', 'GET', '/me', $t);
        $this->record('teacher.contexts', 'GET', '/me/contexts', $t);
        $this->record('teacher.applications', 'GET', '/me/applications', $t);
        $this->record('teacher.context', 'POST', '/me/context', $t, ['establishment_id' => $this->school]);
        $this->record('teacher.todo', 'GET', '/me/todo', $t);
        [$status] = $this->record('teacher.timetable', 'GET', '/timetable?teacher_person_id=' . $teacher['person_id'] . '&week=' . $today, $t, null, $this->school);
        self::assertSame(200, $status);
        foreach (['teacher.structures' => '/structures', 'teacher.enrollments' => '/enrollments?filter[structure_id]=' . $this->class, 'teacher.people' => '/people', 'teacher.periods' => '/periods'] as $name => $path) {
            [$status] = $this->record($name, 'GET', $path, $t, null, $this->school);
            self::assertSame(200, $status, $name . ' : l\'écran d\'appel ou de notes de l\'enseignant ne peut pas se charger.');
        }
        $this->record('teacher.attendance.sync', 'POST', '/attendance-sessions/sync', $t, ['sessions' => [[
            'client_uuid' => (string) new Ulid(), 'slot_id' => $slot, 'date' => $today, 'validate' => true,
            'records' => array_map(static fn (string $p, int $i): array => ['person_id' => $p, 'status' => $i === 1 ? 'A' : 'P', 'client_uuid' => (string) new Ulid()], $learners, array_keys($learners)),
        ]]], $this->school);
        [$status] = $this->record('teacher.gradesheet', 'GET', '/grade-sheets?structure_id=' . $this->class . '&period_id=' . $this->periods[0], $t, null, $this->school);
        self::assertSame(200, $status, 'Grille de notes de l\'enseignant.');
        $uuid = (string) new Ulid();
        $this->record('teacher.grades.sync.ok', 'POST', '/grades/sync', $t, ['entries' => [['client_uuid' => $uuid, 'assessment_id' => $assessment, 'person_id' => $learners[0], 'value' => 15, 'base_version' => 0]]], $this->school);
        $this->ok('POST', '/grades', $this->admin, ['assessment_id' => $assessment, 'person_id' => $learners[0], 'value' => 12], $this->school);
        $this->record('teacher.grades.sync.conflict', 'POST', '/grades/sync', $t, ['entries' => [['client_uuid' => (string) new Ulid(), 'assessment_id' => $assessment, 'person_id' => $learners[0], 'value' => 17, 'base_version' => 1]]], $this->school);
        $this->record('teacher.grades.invalid', 'POST', '/grades', $t, ['assessment_id' => $assessment, 'person_id' => $learners[1], 'value' => 22], $this->school);
        $this->record('teacher.gradesheet.after', 'GET', '/grade-sheets?structure_id=' . $this->class . '&period_id=' . $this->periods[0], $t, null, $this->school);

        // Parent : portail famille et paiement.
        $p = $this->signIn($parent['login'], $parent['password']);
        $this->record('parent.me', 'GET', '/me', $p);
        $this->record('parent.contexts', 'GET', '/me/contexts', $p);
        $this->record('parent.applications', 'GET', '/me/applications', $p);
        $this->record('parent.context', 'POST', '/me/context', $p, ['establishment_id' => $this->school]);
        $this->record('parent.todo', 'GET', '/me/todo', $p);
        [$status] = $this->record('parent.portal', 'GET', '/portal/family', $p);
        self::assertSame(200, $status);
        $this->record('parent.references', 'GET', '/payment-references?filter[guarantor_id]=' . $parent['person_id'], $p, null, $this->school);
        $this->record('parent.payment', 'POST', '/payments', $p, ['channel' => 'orange_money', 'amount_xaf' => 50000, 'family_reference' => 'FAM-0001', 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'enregistrement-0001']);
        $this->record('cashier.payment.pending', 'POST', '/payments', $this->admin, ['channel' => 'orange_money', 'amount_xaf' => 50000, 'family_reference' => (string) $this->ok('POST', '/payment-references', $this->admin, ['guarantor_id' => $parent['person_id']], $this->school, [], 201)['data']['reference'], 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'enregistrement-0002']);

        if (getenv('RECORD_UI_FIXTURES') === '1') {
            $file = dirname(__DIR__, 2) . '/web/e2e/fixtures/recorded.json';
            @mkdir(dirname($file), 0o777, true);
            file_put_contents($file, json_encode(['recorded_at' => date(DATE_ATOM), 'note' => 'Réponses de la pile réelle (SQLite, données fictives). Régénérer : RECORD_UI_FIXTURES=1 php vendor/bin/phpunit tests/E2E/UiFixturesRecorderTest.php', 'calls' => $this->recorded], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::assertNotEmpty($this->recorded);
    }
}
