<?php

declare(strict_types=1);

namespace Tests\Feature\Organisation;

use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUsage;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;
use Tests\Support\Organisation\FakePeriodUsage;
use Tests\Support\Organisation\OrganisationTestCase;

/**
 * ANNEE-EDIT : modification d'une année et de ses périodes, suppression d'une période libre (DELETE /periods/{id}).
 * Cas réel (fictif) : un IPES dont le Semestre 1 finit par erreur le 27/11/2026 ; on le prolonge jusqu'à mi-février.
 */
final class PeriodEditTest extends OrganisationTestCase
{
    private string $year;
    private FakePeriodUsage $usage;

    protected function setUp(): void
    {
        $this->usage = new FakePeriodUsage();
        $this->bootOrganisation([PeriodUsage::PREFIX . 'test' => [$this->usage]]);
        $this->seedEstablishment('t1', 'e1', 'Institut Les Rôniers', 'higher', 'alice', 'carol');
        $this->seedEstablishment('t1', 'e2', 'Institut du Lac', 'higher', 'alice');
        $this->seedEstablishment('t1', 'e3', 'Collège Les Manguiers', 'secondary', 'alice');
        $this->grantAll('alice');
        $this->grantAll('carol', 'organisation.read');
        $this->year = $this->createYear('e1');
    }

    private function createYear(string $establishment, string $label = '2026-2027', string $from = '2026-09-07', string $to = '2027-07-30'): string
    {
        [$response, $json] = $this->api('POST', '/academic-years', 'alice', 't1', $establishment, ['label' => $label, 'starts_on' => $from, 'ends_on' => $to]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['id'];
    }

    private function period(string $kind, int $ordinal, string $from, string $to, ?string $parent = null, string $establishment = 'e1', ?string $year = null): string
    {
        $body = ['academic_year_id' => $year ?? $this->year, 'kind' => $kind, 'ordinal' => $ordinal, 'starts_on' => $from, 'ends_on' => $to] + ($parent === null ? [] : ['parent_id' => $parent]);
        [$response, $json] = $this->api('POST', '/periods', 'alice', 't1', $establishment, $body);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['id'];
    }

    /** @return list<string> actions d'audit journalisées pour cet objet */
    private function auditActions(string $objectId): array
    {
        $statement = $this->connections()->pdo()->prepare('SELECT action FROM audit_log WHERE object_id = ? ORDER BY occurred_at');
        $statement->execute([$objectId]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function patch(string $path, array $body, string $user = 'alice', string $establishment = 'e1'): array
    {
        [$response, $json] = $this->api('PATCH', $path, $user, 't1', $establishment, $body);

        return [$response->getStatusCode(), $json];
    }

    public function testModifierLAnneeEtLesSemestres(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');
        $s2 = $this->period('semester', 2, '2026-11-28', '2027-07-30');

        [$status, $json] = $this->patch('/academic-years/' . $this->year, ['label' => '2026-2027 IPES', 'ends_on' => '2027-07-31']);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('2026-2027 IPES', $json['data']['label']);
        self::assertSame('2027-07-31', $json['data']['ends_on']);

        [$status, $json] = $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14']);
        self::assertSame(422, $status, 'S1 prolongé chevaucherait S2 : il faut d\'abord déplacer S2.');
        self::assertStringContainsString('Semestre 2', $json['errors']['starts_on'][0]);

        self::assertSame(200, $this->patch('/periods/' . $s2, ['starts_on' => '2027-02-15'])[0]);
        [$status, $json] = $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14']);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('2027-02-14', $json['data']['ends_on']);
        self::assertArrayNotHasKey('meta', $json, 'Aucun avertissement pour une période libre.');

        self::assertContains('period.updated', $this->auditActions($s1));
    }

    public function testPeriodeHorsDeLAnneeRefusee(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2027-01-31');

        [$status, $json] = $this->patch('/periods/' . $s1, ['ends_on' => '2027-08-15']);
        self::assertSame(422, $status);
        self::assertStringContainsString('comprise dans l\'année scolaire 2026-2027', $json['errors']['ends_on'][0]);
        self::assertSame(422, $this->patch('/periods/' . $s1, ['starts_on' => '2026-09-01'])[0], 'Début avant l\'année.');
    }

    public function testChevauchementDePeriodesDeMemeNatureRefuse(): void
    {
        $this->period('semester', 1, '2026-09-07', '2027-02-14');
        [$response, $json] = $this->api('POST', '/periods', 'alice', 't1', 'e1', ['academic_year_id' => $this->year, 'kind' => 'semester', 'ordinal' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-07-30']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('chevauchent « Semestre 1 »', $json['errors']['starts_on'][0]);

        [$response, $json] = $this->api('POST', '/periods', 'alice', 't1', 'e1', ['academic_year_id' => $this->year, 'kind' => 'semester', 'ordinal' => 1, 'starts_on' => '2027-02-15', 'ends_on' => '2027-07-30']);
        self::assertSame(409, $response->getStatusCode(), 'Numéro déjà pris : conflit, avec un message clair.');
        self::assertStringContainsString('déjà pris par « Semestre 1 »', $json['detail']);
    }

    public function testLesPeriodesImbriqueesRestentPermises(): void
    {
        $year = $this->createYear('e3', '2026-2027', '2026-09-01', '2027-06-30');
        $annual = $this->period('year', 1, '2026-09-01', '2027-06-30', null, 'e3', $year);
        $t1 = $this->period('trimester', 1, '2026-09-01', '2026-12-18', $annual, 'e3', $year);
        $this->period('sequence', 1, '2026-09-01', '2026-10-16', $t1, 'e3', $year);
        $this->period('sequence', 2, '2026-10-19', '2026-12-18', $t1, 'e3', $year);

        [$response] = $this->api('POST', '/periods', 'alice', 't1', 'e3', ['academic_year_id' => $year, 'kind' => 'sequence', 'ordinal' => 3, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-18', 'parent_id' => $t1]);
        self::assertSame(422, $response->getStatusCode(), 'Deux séquences du même trimestre ne se chevauchent pas.');
    }

    public function testReduireLAnneeRefuseSiUnePeriodeEnSort(): void
    {
        $this->period('semester', 1, '2026-09-07', '2027-02-14');
        $this->period('semester', 2, '2027-02-15', '2027-07-30');

        [$status, $json] = $this->patch('/academic-years/' . $this->year, ['ends_on' => '2027-06-30']);
        self::assertSame(422, $status);
        self::assertStringContainsString('« Semestre 2 » (du 15/02/2027 au 30/07/2027) sortirait de l\'année', $json['errors']['ends_on'][0]);
        [$status, $json] = $this->patch('/academic-years/' . $this->year, ['starts_on' => '2026-09-14']);
        self::assertSame(422, $status);
        self::assertStringContainsString('Semestre 1', $json['errors']['starts_on'][0]);
        self::assertSame(200, $this->patch('/academic-years/' . $this->year, ['starts_on' => '2026-09-01', 'ends_on' => '2027-08-15'])[0], 'Élargir reste permis.');
    }

    public function testSupprimerUnePeriodeLibre(): void
    {
        $s2 = $this->period('semester', 2, '2026-11-28', '2027-07-30');

        [$response, $json] = $this->api('DELETE', '/periods/' . $s2, 'alice', 't1', 'e1');
        self::assertSame(200, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertTrue($json['data']['deleted']);
        self::assertSame(404, $this->code('GET', '/periods/' . $s2, 'alice', 't1', 'e1'));
        self::assertContains('period.deleted', $this->auditActions($s2));
    }

    public function testSuppressionRefuseeSiLaPeriodeEstUtilisee(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');
        $this->usage->uses[$s1] = [new PeriodUse('jury(s) de délibération', 2)];

        [$response, $json] = $this->api('DELETE', '/periods/' . $s1, 'alice', 't1', 'e1');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('« Semestre 1 » est déjà utilisée (2 jury(s) de délibération)', $json['detail']);
        self::assertSame(200, $this->code('GET', '/periods/' . $s1, 'alice', 't1', 'e1'));
    }

    public function testUneEvaluationReelleBloqueLaSuppressionEtLeDeplacement(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');
        $this->connections()->pdo()->prepare(
            'INSERT INTO assessments (id, tenant_id, establishment_id, subject_id, structure_id, period_id, kind, title, weight, max_score, grading_mode, anonymous, status, held_on, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['01JASSESS0000000000000001', 't1', 'e1', 'SUBJ1', 'STRUCT1', $s1, 'cc', 'Contrôle continu 1', 1, 20, 'numeric', 0, 'draft', '2026-10-15', 'alice', '2026-10-15T08:00:00+01:00', '2026-10-15T08:00:00+01:00']);

        [$response, $json] = $this->api('DELETE', '/periods/' . $s1, 'alice', 't1', 'e1');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('1 évaluation(s)', $json['detail']);

        [$status, $json] = $this->patch('/periods/' . $s1, ['starts_on' => '2026-10-20']);
        self::assertSame(422, $status, 'L\'évaluation du 15/10 sortirait de la période.');
        self::assertStringContainsString('datée(s) du 15/10/2026 au 15/10/2026', $json['errors']['starts_on'][0]);

        [$status, $json] = $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14']);
        self::assertSame(200, $status, 'Prolonger reste permis, avec un avertissement.');
        self::assertStringContainsString('déjà utilisée (1 évaluation(s))', $json['meta']['warnings'][0]);
    }

    public function testTousLesFournisseursDesModulesRepondentSurLaBaseMigree(): void
    {
        $container = $this->kernel->container();
        $keys = array_values(array_filter($container->getKnownEntryNames(), static fn (string $name): bool => str_starts_with($name, PeriodUsage::PREFIX)));
        foreach (['assessment', 'higher_deliberation', 'higher_tutelage', 'nursery_development', 'primary_councils', 'primary_grades', 'primary_remediation', 'primary_report_cards',
            'public_nursery_development', 'public_primary', 'secondary_councils', 'secondary_dashboards', 'secondary_grades', 'secondary_official_exams'] as $module) {
            self::assertContains(PeriodUsage::PREFIX . $module, $keys);
        }
        foreach ($keys as $key) {
            foreach ((array) $container->get($key) as $entry) {
                $provider = is_string($entry) ? $container->get($entry) : $entry;
                self::assertInstanceOf(PeriodUsage::class, $provider);
                self::assertSame([], $provider->uses('t1', 'e1', '01JNOPERIOD000000000000000'), $key . ' : aucune donnée, aucun usage (et SQL valide).');
            }
        }
    }

    public function testSuppressionRefuseeSiDesPeriodesYSontRattachees(): void
    {
        $year = $this->createYear('e3', '2026-2027', '2026-09-01', '2027-06-30');
        $t1 = $this->period('trimester', 1, '2026-09-01', '2026-12-18', null, 'e3', $year);
        $this->period('sequence', 1, '2026-09-01', '2026-10-16', $t1, 'e3', $year);

        [$response, $json] = $this->api('DELETE', '/periods/' . $t1, 'alice', 't1', 'e3');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('périodes rattachées', $json['detail']);
    }

    public function testDroitsOrganisationManage(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');

        self::assertSame(200, $this->code('GET', '/periods/' . $s1, 'carol', 't1', 'e1'), 'Lecture permise.');
        self::assertSame(403, $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14'], 'carol')[0]);
        self::assertSame(403, $this->patch('/academic-years/' . $this->year, ['label' => 'X'], 'carol')[0]);
        self::assertSame(403, $this->code('DELETE', '/periods/' . $s1, 'carol', 't1', 'e1'));
        self::assertSame(200, $this->code('GET', '/periods/' . $s1, 'alice', 't1', 'e1'), 'Toujours là.');
    }

    public function testAutreEtablissementDonne404(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');

        self::assertSame(404, $this->code('DELETE', '/periods/' . $s1, 'alice', 't1', 'e2'));
        self::assertSame(404, $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14'], 'alice', 'e2')[0]);
        self::assertSame(404, $this->patch('/academic-years/' . $this->year, ['label' => 'X'], 'alice', 'e2')[0]);
        self::assertSame(404, $this->code('DELETE', '/periods/' . $s1, 'alice', 't2', 'e1'), 'Autre client.');
        self::assertSame(200, $this->code('GET', '/periods/' . $s1, 'alice', 't1', 'e1'));
    }

    public function testAnneeClotureeNonModifiable(): void
    {
        $s1 = $this->period('semester', 1, '2026-09-07', '2026-11-27');
        $this->connections()->pdo()->prepare("UPDATE academic_years SET status = 'closed' WHERE id = ?")->execute([$this->year]);

        self::assertSame(409, $this->patch('/academic-years/' . $this->year, ['label' => '2026-2027 bis'])[0]);
        self::assertSame(409, $this->patch('/periods/' . $s1, ['ends_on' => '2027-02-14'])[0]);
        [$response, $json] = $this->api('DELETE', '/periods/' . $s1, 'alice', 't1', 'e1');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('clôturée', $json['detail']);
    }
}
