<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Internships;

use Tests\Support\Higher\Internships\InternshipsTestCase;

/** EX-STG-01 à 04 : entreprises, convention tripartite (DOC-20) et son cycle, suivi et fiche d'évaluation (DOC-23). */
final class ConventionLifecycleTest extends InternshipsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInternships();
    }

    public function testRepertoireDesEntreprises(): void
    {
        $company = $this->company();
        self::assertTrue($company['active']);
        $updated = $this->ok('PATCH', '/higher/internship-companies/' . $company['id'], ['active' => false, 'phone' => '+237699000000'], self::OFFICER);
        self::assertFalse($updated['active']);
        self::assertCount(1, $this->ok('GET', '/higher/internship-companies', null, self::DIRECTOR));
        self::assertSame(403, $this->call('POST', '/higher/internship-companies', ['name' => 'X'], self::DIRECTOR)[0]);
        self::assertSame(422, $this->call('POST', '/higher/internship-companies', ['name' => 'X', 'inconnu' => 1], self::OFFICER)[0]);
    }

    public function testCycleCompletDeLaConvention(): void
    {
        $draft = $this->convention();
        self::assertSame('draft', $draft['status']);
        self::assertSame('Dr Kamga', $draft['supervisor_name']);
        self::assertSame($this->org['department'], $draft['department_id']);

        $edited = $this->ok('PATCH', '/higher/internships/' . $draft['id'], ['subject' => 'Audit interne'], self::OFFICER);
        self::assertSame('Audit interne', $edited['subject']);

        $pending = $this->ok('POST', '/higher/internships/' . $draft['id'] . '/convention', null, self::OFFICER);
        self::assertSame('pending_signatures', $pending['status']);
        self::assertSame(['DOC-20'], $this->fakes->codes());
        self::assertSame('M. Tchana', $this->fakes->issuedDocs[0]['data']['company_tutor']);
        self::assertSame(409, $this->call('PATCH', '/higher/internships/' . $draft['id'], ['subject' => 'Trop tard'], self::OFFICER)[0], 'Brouillon seulement.');

        $this->ok('POST', '/higher/internships/' . $draft['id'] . '/signatures', ['party' => 'student', 'signed_on' => '2026-05-20'], self::OFFICER);
        $this->ok('POST', '/higher/internships/' . $draft['id'] . '/signatures', ['party' => 'company', 'signed_on' => '2026-05-21'], self::OFFICER);
        self::assertSame(403, $this->call('POST', '/higher/internships/' . $draft['id'] . '/signatures', ['party' => 'school', 'signed_on' => '2026-05-22'], self::OFFICER)[0], 'L\'école signe par le directeur.');
        $signed = $this->ok('POST', '/higher/internships/' . $draft['id'] . '/signatures', ['party' => 'school', 'signed_on' => '2026-05-22'], self::DIRECTOR);
        self::assertSame('signed', $signed['status']);
        self::assertSame(['student' => '2026-05-20', 'company' => '2026-05-21', 'school' => '2026-05-22'], $signed['signatures']);

        $this->ok('POST', '/higher/internships/' . $draft['id'] . '/subject/validate', null, self::OFFICER);
        $completed = $this->ok('POST', '/higher/internships/' . $draft['id'] . '/completion', ['received_on' => '2026-09-02'], self::OFFICER);
        self::assertSame('completed', $completed['status']);
        self::assertSame(409, $this->call('POST', '/higher/internships/' . $draft['id'] . '/cancel', ['reason' => 'Erreur'], self::OFFICER)[0]);
        self::assertContains('higher.internship.signed', array_column($this->audit->entries, 'action'));
    }

    public function testInscriptionNonValideeOuConventionEnDouble(): void
    {
        $this->fakes->enrol('p3', $this->org['school'], $this->org['program'], $this->org['l2'], null, self::YEAR, 'pending');
        $company = $this->company();
        $body = ['company_id' => $company['id'], 'subject' => 'S', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31', 'company_tutor_name' => 'T', 'supervisor_person_id' => 'sup1'];
        self::assertSame(409, $this->call('POST', '/higher/internships', ['enrolment_id' => 'enr-p3'] + $body, self::OFFICER)[0]);
        $this->convention();
        self::assertSame(409, $this->call('POST', '/higher/internships', ['enrolment_id' => 'enr-p1'] + $body, self::OFFICER)[0]);
        [$status, $problem] = $this->call('POST', '/higher/internships', ['enrolment_id' => 'enr-p2', 'ends_on' => '2026-05-01'] + $body, self::OFFICER);
        self::assertSame(422, $status);
        self::assertArrayHasKey('ends_on', $problem['errors']);
    }

    public function testSuiviEtFicheDEvaluation(): void
    {
        $draft = $this->convention();
        $visit = ['kind' => 'visit', 'occurred_on' => '2026-07-01', 'summary' => 'Visite sur site'];
        self::assertSame(409, $this->call('POST', '/higher/internships/' . $draft['id'] . '/followups', $visit, self::OFFICER)[0], 'Avant signature.');

        $id = (string) $this->signed('p2')['id'];
        $this->ok('POST', '/higher/internships/' . $id . '/followups', $visit, self::OFFICER);
        self::assertSame(422, $this->call('POST', '/higher/internships/' . $id . '/followups', ['kind' => 'evaluation', 'occurred_on' => '2026-08-30', 'summary' => 'Bien'], self::OFFICER)[0]);
        self::assertSame(422, $this->call('POST', '/higher/internships/' . $id . '/followups', ['kind' => 'evaluation', 'occurred_on' => '2026-08-30', 'summary' => 'Bien', 'score' => 15.3], self::OFFICER)[0]);
        $evaluation = $this->ok('POST', '/higher/internships/' . $id . '/followups', ['kind' => 'evaluation', 'occurred_on' => '2026-08-30', 'summary' => 'Très investie', 'score' => 15.5], self::OFFICER);
        self::assertEquals(15.5, $evaluation['score']);
        self::assertSame('DOC-23', $this->fakes->codes()[1]);
        self::assertSame('15,50/20', $this->fakes->issuedDocs[1]['data']['evaluation_score']);
        self::assertCount(2, $this->ok('GET', '/higher/internships/' . $id . '/followups', null, self::DIRECTOR));
    }
}
