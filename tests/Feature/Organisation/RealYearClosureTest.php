<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Core\Support\Ulid;
use Tests\Support\Organisation\OrganisationTestCase;

/**
 * Clôture d'année de bout en bout avec les VRAIS fournisseurs (Billing, Assessment, People), sans doublure :
 * contrôles évalués, soldes reportés, admis promus en brouillon d'inscription, le tout rejoué sans doublon.
 */
final class RealYearClosureTest extends OrganisationTestCase
{
    private string $year = '';
    private string $next = '';
    private string $class1 = '';
    private string $class2 = '';

    protected function setUp(): void
    {
        $this->bootOrganisation();
        $this->seedEstablishment('t1', 'e1', 'Lycée', 'secondary', 'alice', 'bob');
        foreach (['alice', 'bob'] as $user) {
            $this->grantAll($user);
        }
        $this->secondFactor->allow('bob');
        $this->year = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])[1]['data']['id'];
        $this->next = $this->api('POST', '/academic-years', 'alice', 't1', 'e1', ['label' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30'])[1]['data']['id'];
        $this->class1 = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'class', 'name' => '6e A', 'capacity' => 50])[1]['data']['id'];
        $this->class2 = $this->api('POST', '/structures', 'alice', 't1', 'e1', ['kind' => 'class', 'name' => '5e A', 'capacity' => 50])[1]['data']['id'];
        $this->api('POST', '/authorizations', 'alice', 't1', 'e1', [
            'structure_id' => $this->class2, 'reference' => 'ARR-5E', 'authority' => 'MINESEC', 'valid_from' => '2026-01-01', 'valid_to' => '2028-12-31',
        ]);
        $this->api('PUT', '/settings', 'alice', 't1', 'e1', ['scope' => 'establishment', 'key' => 'people.promotion.next_structure', 'value' => [$this->class1 => $this->class2]]);

        $this->insert('people', ['id' => 'ada', 'tenant_id' => 't1', 'first_name' => 'Ada', 'last_name' => 'Un', 'locale' => 'fr', 'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00']);
        $this->insert('people', ['id' => 'bob-learner', 'tenant_id' => 't1', 'first_name' => 'Bob', 'last_name' => 'Deux', 'locale' => 'fr', 'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00']);
        foreach (['ada', 'bob-learner'] as $learner) {
            $this->insert('enrollments', [
                'id' => (string) new Ulid(), 'tenant_id' => 't1', 'person_id' => $learner, 'establishment_id' => 'e1', 'structure_id' => $this->class1,
                'academic_year_id' => $this->year, 'status' => 'active', 'completeness' => 100, 'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00',
            ]);
        }
        $this->insert('assessments', [
            'id' => (string) new Ulid(), 'tenant_id' => 't1', 'establishment_id' => 'e1', 'subject_id' => 'subj', 'structure_id' => $this->class1, 'period_id' => 'p1',
            'academic_year_id' => $this->year, 'kind' => 'devoir', 'title' => 'Devoir', 'weight' => 1, 'max_score' => 20, 'grading_mode' => 'numeric', 'anonymous' => 0,
            'status' => 'locked', 'created_by' => 'x', 'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00',
        ]);
        foreach (['ada' => 'Admis', 'bob-learner' => 'Ajourné'] as $learner => $decision) {
            $this->insert('results', [
                'id' => (string) new Ulid(), 'tenant_id' => 't1', 'establishment_id' => 'e1', 'person_id' => $learner, 'structure_id' => $this->class1, 'period_id' => 'p1',
                'period_label' => 'Séquence 1', 'rule_version' => 1, 'details' => '[]', 'status' => 'published', 'computed_at' => '2027-06-20T08:00:00+01:00', 'decision' => $decision,
            ]);
        }
        $invoiceId = (string) new Ulid();
        $this->insert('invoices', [
            'id' => $invoiceId, 'tenant_id' => 't1', 'establishment_id' => 'e1', 'enrollment_id' => 'en-ada', 'learner_id' => 'ada', 'academic_year_id' => $this->year,
            'status' => 'open', 'gross_xaf' => 500000, 'discount_xaf' => 0, 'net_xaf' => 500000, 'paid_xaf' => 200000, 'balance_xaf' => 300000,
            'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00',
        ]);
        $this->insert('installments', [
            'id' => (string) new Ulid(), 'tenant_id' => 't1', 'establishment_id' => 'e1', 'invoice_id' => $invoiceId, 'line_id' => (string) new Ulid(), 'learner_id' => 'ada',
            'kind' => 'tuition', 'seq_no' => 1, 'label' => 'Pension', 'due_on' => '2027-01-15', 'amount_xaf' => 500000, 'paid_xaf' => 200000,
        ]);
    }

    /** @param array<string, scalar|null> $row */
    private function insert(string $table, array $row): void
    {
        $this->connections()->pdo()->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))))
            ->execute(array_values($row));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->connections()->pdo()->query($sql)->fetchAll();
    }

    public function testLesTroisControlesSontEvaluesParLesVraisModules(): void
    {
        $data = $this->api('GET', '/academic-years/' . $this->year . '/closure-check', 'alice', 't1', 'e1')[1]['data'];
        $checks = array_column($data['checks'], null, 'key');

        self::assertSame(0, $data['not_evaluable_count']);
        self::assertTrue($data['ready']);
        self::assertSame(['ok', 'ok', 'ok'], [$checks['billing.unpaid']['status'], $checks['assessment.grades_unlocked']['status'], $checks['assessment.deliberations_open']['status']]);
        self::assertStringContainsString('1 facture(s) impayée(s) pour 300000 XAF', $checks['billing.unpaid']['detail']);
    }

    public function testDesNotesNonVerrouilleesBloquentLaDemande(): void
    {
        $this->connections()->pdo()->exec("UPDATE assessments SET status = 'entry'");

        [$response, $body] = $this->api('POST', '/academic-years/' . $this->year . '/close', 'alice', 't1', 'e1', ['next_academic_year_id' => $this->next]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Notes non verrouillées (1)', $body['detail']);
    }

    public function testClotureCompleteReporteLesSoldesEtPromeutLesAdmisPuisSeRejoueSansDoublon(): void
    {
        [$response, $body] = $this->closeYear($this->year, 'e1', 't1', ['next_academic_year_id' => $this->next]);

        self::assertSame(200, $response->getStatusCode(), json_encode($body));
        self::assertSame('closed', $body['data']['status']);
        self::assertSame(['invoices_carried' => 1, 'amount_xaf' => 300000], $body['data']['steps']['carry_over']['counts']);
        self::assertSame(1, $body['data']['steps']['promotion']['counts']['promoted']);
        self::assertSame(1, $body['data']['steps']['promotion']['counts']['repeating']);

        $carried = $this->rows("SELECT * FROM invoices WHERE academic_year_id = '" . $this->next . "'");
        self::assertCount(1, $carried);
        self::assertSame([300000, 'ada', 'open'], [(int) $carried[0]['balance_xaf'], $carried[0]['learner_id'], $carried[0]['status']]);
        self::assertSame('carried', $this->rows("SELECT status FROM invoices WHERE academic_year_id = '" . $this->year . "'")[0]['status']);
        $drafts = $this->rows("SELECT * FROM enrollments WHERE academic_year_id = '" . $this->next . "'");
        self::assertCount(1, $drafts);
        self::assertSame(['ada', $this->class2, 'draft'], [$drafts[0]['person_id'], $drafts[0]['structure_id'], $drafts[0]['status']]);

        $again = $this->api('POST', '/academic-years/' . $this->year . '/close/approve', 'bob', 't1', 'e1');
        self::assertSame(200, $again[0]->getStatusCode());
        self::assertCount(1, $this->rows("SELECT id FROM invoices WHERE academic_year_id = '" . $this->next . "'"));
        self::assertCount(1, $this->rows("SELECT id FROM enrollments WHERE academic_year_id = '" . $this->next . "'"));
    }
}
