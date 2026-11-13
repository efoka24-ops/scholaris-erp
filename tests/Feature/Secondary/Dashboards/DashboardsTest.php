<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Dashboards;

use Scholaris\Modules\Apps\Secondary\Dashboards\Application\SecondaryIndicators;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Reporting\Contracts\IndicatorScope;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;

/**
 * Pilotage E4-A (T970, T971) : objectifs O1 à O7 calculés sur un jeu fictif connu, tableaux de bord par rôle et périmètre, vue réseau
 * agrégée et masquée (jamais nominative), rapports en fichier joint, isolation entre clients.
 */
final class DashboardsTest extends DashboardWorld
{
    private const HEAD = ['secondary.dashboard.read', 'secondary.fees.read', 'secondary.settings.manage'];
    private const CENSOR = ['secondary.dashboard.read', 'secondary.grades.validate'];

    /** @return array{school: string, classes: list<string>} */
    private function privateSchool(): array
    {
        $world = $this->school('college_prive');
        [$a, $b] = $world['classes'];
        $s = $world['school'];
        $this->student(self::TA, $s, $a, 'p1', 'female');
        $this->student(self::TA, $s, $a, 'p2', 'male', '2012-01-15');
        $this->student(self::TA, $s, $b, 'p3', 'female');
        $this->student(self::TA, $s, $b, 'p4', null, null);
        $this->hours = ['2026-09-07' => ['p1' => 4, 'p2' => 6, 'p3' => 2], '2026-11-01' => ['p1' => 1, 'p2' => 1]];
        $this->invoices = ['p1' => ['inv-1'], 'p2' => ['inv-2']];
        $this->totals = [
            'inv-1' => ['net_xaf' => 100000, 'paid_xaf' => 90000, 'balance_xaf' => 10000, 'overdue_xaf' => 0],
            'inv-2' => ['net_xaf' => 100000, 'paid_xaf' => 50000, 'balance_xaf' => 50000, 'overdue_xaf' => 20000],
        ];
        $this->coverageRates = [$a => 0.5, $b => null];
        $this->staff[$s] = [
            new StaffMemberInfo('st-1', 'prof-1', self::TA, 'permanent', 'active', 'Professeur', 'teaching', true),
            new StaffMemberInfo('st-2', 'sec-1', self::TA, 'vacataire', 'active', 'Secrétaire', 'administrative', false),
        ];
        $now = '2026-10-01T08:00:00+01:00';
        foreach ([['p1', 'recorded'], ['p2', 'recorded'], ['p3', 'pending']] as $i => [$person, $status]) {
            $this->row('se_cs_students', ['id' => 'cs-' . $i, 'tenant_id' => self::TA, 'establishment_id' => $s, 'person_id' => $person, 'national_id' => $status === 'recorded' ? 'MAT00' . $i . 'X' : null, 'id_status' => $status, 'origin' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[$a, 'p1', '2026-10-28T10:00:00+01:00'], [$a, 'p2', '2026-10-29T10:00:00+01:00'], [$b, 'p3', '2026-11-05T10:00:00+01:00']] as $i => [$class, $person, $at]) {
            $this->row('se_report_snapshots', ['id' => 'snap-' . $i, 'tenant_id' => self::TA, 'establishment_id' => $s, 'council_id' => 'c-' . $class, 'person_id' => $person, 'structure_id' => $class, 'period_id' => self::T1, 'variables' => '{}', 'built_at' => $at]);
        }
        $this->row('se_official_exam_sessions', ['id' => 'sess-1', 'tenant_id' => self::TA, 'establishment_id' => $s, 'exam_code' => 'BEPC', 'exam_label' => 'BEPC', 'academic_year_id' => self::YEAR, 'class_structure_id' => $a, 'required_pieces' => '[]', 'optional_subjects' => '[]', 'export_columns' => '[]', 'passing_codes' => '["ADMIS"]', 'status' => 'open', 'created_by' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['p1', 'ADMIS'], ['p2', 'ECHEC'], ['p3', null]] as $i => [$person, $code]) {
            $this->row('se_official_exam_candidates', ['id' => 'cand-' . $i, 'tenant_id' => self::TA, 'establishment_id' => $s, 'session_id' => 'sess-1', 'enrollment_id' => 'enr-' . $person, 'person_id' => $person, 'class_structure_id' => $a, 'full_name' => 'Élève fictif', 'pieces_received' => '[]', 'selected_subjects' => '[]', 'result_code' => $code, 'created_by' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->grant(self::TA, 'chef', $s, self::HEAD);

        return $world;
    }

    /**
     * @param list<array<string, mixed>> $objectives
     * @return array<string, array<string, mixed>>
     */
    private static function byCode(array $objectives): array
    {
        return array_column($objectives, null, 'code');
    }

    public function testLesObjectifsDuChefSontCalculesSurLeJeuConnu(): void
    {
        ['school' => $s, 'classes' => [$a]] = $this->privateSchool();
        $this->grant(self::TA, 'censeur', $s, self::CENSOR);
        $claim = $this->ok('POST', '/secondary/dashboards/claims', ['academic_year_id' => self::YEAR, 'period_id' => self::T1, 'class_id' => $a, 'person_id' => 'p1', 'claim_text' => 'Moyenne de mathématiques mal calculée'], 'censeur', self::TA, $s);
        $this->ok('POST', '/secondary/dashboards/claims/' . $claim['id'] . '/close', ['outcome' => 'calculation_error', 'outcome_note' => 'Coefficient corrigé'], 'censeur', self::TA, $s);
        [$again] = $this->call('POST', '/secondary/dashboards/claims/' . $claim['id'] . '/close', ['outcome' => 'unfounded', 'outcome_note' => 'x'], 'censeur', self::TA, $s);
        self::assertSame(409, $again);
        [$stranger] = $this->call('POST', '/secondary/dashboards/claims', ['academic_year_id' => self::YEAR, 'class_id' => $a, 'person_id' => 'inconnu', 'claim_text' => 'x'], 'censeur', self::TA, $s);
        self::assertSame(422, $stranger);

        $head = $this->ok('GET', '/secondary/dashboards/head?period_id=' . self::T1, null, 'chef', self::TA, $s);
        $o = self::byCode($head['objectives']);
        self::assertSame(1, $o['O1']['value']);
        self::assertFalse($o['O1']['met']);
        self::assertEquals(50.0, $o['O2']['value'], 'classe A remise le 28/10 (délai 29/10), classe B le 5/11');
        self::assertSame(['due' => 2, 'on_time' => 1, 'late' => 1, 'missing' => 0], array_intersect_key($o['O2']['detail'], array_flip(['due', 'on_time', 'late', 'missing'])));
        self::assertEquals(50.0, $o['O3']['value']);
        self::assertEquals(70.0, $o['O4']['value']);
        self::assertFalse($o['O4']['met']);
        self::assertFalse($o['O5']['available']);
        self::assertSame('not_applicable', $o['O5']['reason']);
        self::assertEquals(50.0, $o['O6']['value']);
        self::assertEquals(50.0, $o['O6']['detail']['average_coverage_percent']);
        self::assertEquals(3.0, $o['O7']['value'], 'T1 : 12 heures pour 4 élèves');
        self::assertNull($o['O7']['met'], 'pas de trimestre de référence avant T1');
        self::assertSame(['classes' => 2, 'students' => 4, 'female' => 2, 'male' => 1, 'gender_unknown' => 1], array_intersect_key($head['figures'], array_flip(['classes', 'students', 'female', 'male', 'gender_unknown'])));
        self::assertSame(['total' => 2, 'teaching' => 1, 'by_kind' => ['permanent' => 1, 'vacataire' => 1]], $head['figures']['staff']);
        self::assertEquals(50.0, $head['figures']['exams'][0]['pass_rate']);
        self::assertSame('to_confirm', $o['O4']['confirmation']);

        $current = self::byCode($this->ok('GET', '/secondary/dashboards/head', null, 'chef', self::TA, $s)['objectives']);
        self::assertEquals(0.5, $current['O7']['value'], 'T2 : 2 heures pour 4 élèves');
        self::assertEquals(-83.3, $current['O7']['detail']['change_percent']);
        self::assertTrue($current['O7']['met'], 'baisse de plus de 30 %');
        self::assertSame('not_due', $current['O2']['reason']);
    }

    public function testPerimetresEtDroitsParRole(): void
    {
        ['school' => $s, 'classes' => [$a, $b]] = $this->privateSchool();
        $this->grant(self::TA, 'prof', $s, ['secondary.dashboard.read'], Scope::CLASS_GROUP, $a);
        $this->grant(self::TA, 'intendant', $s, ['secondary.finance.read']);
        $this->grant(self::TA, 'surveillant', $s, ['secondary.dashboard.read']);

        $pedagogy = $this->ok('GET', '/secondary/dashboards/pedagogy?period_id=' . self::T1, null, 'prof', self::TA, $s);
        self::assertSame('classes', $pedagogy['scope']);
        self::assertSame([$a], array_column($pedagogy['classes'], 'class_id'));
        self::assertSame(['O1', 'O2', 'O6', 'O7'], array_column($pedagogy['objectives'], 'code'));
        self::assertEquals(5.0, $pedagogy['classes'][0]['unjustified_hours_per_student']);
        [$headStatus] = $this->call('GET', '/secondary/dashboards/head', null, 'prof', self::TA, $s);
        self::assertSame(403, $headStatus);

        $finance = $this->ok('GET', '/secondary/dashboards/finance', null, 'intendant', self::TA, $s);
        self::assertSame('private', $finance['fees_mode']);
        self::assertEquals(70.0, $finance['totals']['recovery_percent']);
        self::assertSame([70, null], array_column($finance['classes'], 'recovery_percent'));
        [$intendantHead] = $this->call('GET', '/secondary/dashboards/head', null, 'intendant', self::TA, $s);
        self::assertSame(403, $intendantHead);

        $surveillant = $this->ok('GET', '/secondary/dashboards/head', null, 'surveillant', self::TA, $s);
        self::assertSame(['O1', 'O2', 'O3', 'O6', 'O7'], array_column($surveillant['objectives'], 'code'), 'sans droit des finances, ni O4 ni O5');
        self::assertSame(['O4', 'O5'], $surveillant['restricted']);
        [$financeStatus] = $this->call('GET', '/secondary/dashboards/finance', null, 'surveillant', self::TA, $s);
        self::assertSame(403, $financeStatus);
        [$network] = $this->call('GET', '/secondary/dashboards/network', null, 'surveillant', self::TA, $s);
        self::assertSame(403, $network);
        [$badPeriod] = $this->call('GET', '/secondary/dashboards/head?period_id=per-s1', null, 'chef', self::TA, $s);
        self::assertSame(422, $badPeriod, 'une séquence n\'est pas un trimestre');
        self::assertNotContains($b, array_column($pedagogy['classes'], 'class_id'));
    }

    public function testEtablissementPublicO5EtIsolationEntreClients(): void
    {
        $world = $this->school('ces', 1);
        $s = $world['school'];
        $this->student(self::TA, $s, $world['classes'][0], 'q1', 'male');
        $now = '2026-10-05T09:00:00+01:00';
        foreach ([['guard', 'pending'], ['manual', 'accepted']] as $i => [$origin, $status]) {
            $this->row('se_fee_requests', ['id' => 'fr-' . $i, 'tenant_id' => self::TA, 'establishment_id' => $s, 'fee_kind' => 'apee', 'label' => 'Reprographie', 'amount' => 2000, 'origin' => $origin, 'requested_by' => 'x', 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->grant(self::TA, 'chef', $s, self::HEAD);
        $o = self::byCode($this->ok('GET', '/secondary/dashboards/head', null, 'chef', self::TA, $s)['objectives']);
        self::assertSame(1, $o['O5']['value']);
        self::assertSame(['blocked' => 1, 'pending' => 1, 'accepted' => 1, 'refused' => 0], array_intersect_key($o['O5']['detail'], array_flip(['blocked', 'pending', 'accepted', 'refused'])));
        self::assertSame('not_applicable', $o['O4']['reason']);

        $this->grant(self::TA, 'censeur', $s, self::CENSOR);
        $claim = $this->ok('POST', '/secondary/dashboards/claims', ['academic_year_id' => self::YEAR, 'class_id' => $world['classes'][0], 'person_id' => 'q1', 'claim_text' => 'Rang contesté'], 'censeur', self::TA, $s);
        $other = $this->school('ces', 1, [], self::TB);
        $this->grant(self::TB, 'censeur-b', $other['school'], self::CENSOR);
        [$foreign] = $this->call('POST', '/secondary/dashboards/claims/' . $claim['id'] . '/close', ['outcome' => 'unfounded', 'outcome_note' => 'x'], 'censeur-b', self::TB, $other['school']);
        self::assertSame(404, $foreign);
        $list = $this->ok('GET', '/secondary/dashboards/claims', null, 'censeur-b', self::TB, $other['school']);
        self::assertSame([], $list);
    }

    public function testLaVueReseauNEstQuAgregeeEtMasqueLesPetitesCases(): void
    {
        $small = $this->school('college_prive', 1, ['network_indicators'], self::TA, 'e-reseau-1');
        foreach (['n1', 'n2', 'n3'] as $p) {
            $this->student(self::TA, $small['school'], $small['classes'][0], $p, 'female');
        }
        $large = $this->school('college_prive', 1, ['network_indicators'], self::TA, 'e-reseau-2');
        foreach (['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7'] as $i => $p) {
            $this->student(self::TA, $large['school'], $large['classes'][0], $p, $i < 4 ? 'female' : 'male');
        }
        $outside = $this->school('college_prive', 1, [], self::TA, 'e-reseau-3');
        $this->student(self::TA, $outside['school'], $outside['classes'][0], 'o1', 'male');
        foreach ([$small, $large, $outside] as $w) {
            $this->grant(self::TA, 'reseau', $w['school'], ['secondary.network.read']);
        }

        $view = $this->ok('GET', '/secondary/dashboards/network', null, 'reseau', self::TA, $small['school']);
        self::assertFalse($view['nominative']);
        self::assertSame(5, $view['minimum_cell_size']);
        self::assertCount(2, $view['establishments'], 'module network_indicators absent : établissement exclu');
        $rows = array_column($view['establishments'], null, 'students');
        self::assertArrayHasKey('', $rows, 'effectif de 3 masqué');
        self::assertSame(['students', 'female', 'male'], $view['establishments'][0]['masked']);
        self::assertNull($view['establishments'][1]['students'], 'masquage secondaire : la case 3 ne se déduit pas du total');
        self::assertSame(10, $view['consolidated']['students']);
        self::assertNull($view['consolidated']['female'], 'une case de sexe sous le seuil : pas de total par sexe');
        $json = (string) json_encode($view);
        foreach (['n1', 'm1', 'person', 'Élève'] as $needle) {
            self::assertStringNotContainsString('"' . $needle, $json, 'aucune donnée nominative');
        }

        $csv = $this->raw('GET', '/secondary/dashboards/reports/network?format=csv', null, 'reseau', self::TA, $small['school']);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('attachment', $csv->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('students, female, male', (string) $csv->getBody());
        [$headReport] = $this->call('GET', '/secondary/dashboards/reports/rentree-classes', null, 'reseau', self::TA, $small['school']);
        self::assertSame(403, $headReport);
    }

    public function testRapportsEnFichierJoint(): void
    {
        ['school' => $s, 'classes' => [$a, $b]] = $this->privateSchool();
        $this->stored[$a . '|per-year'] = [
            'p1' => ['person_id' => 'p1', 'average' => 12.5, 'rank' => 1, 'class_size' => 2, 'mention' => null, 'proposal' => null, 'decision' => null, 'details' => [], 'extras' => []],
            'p2' => ['person_id' => 'p2', 'average' => 8.0, 'rank' => 2, 'class_size' => 2, 'mention' => null, 'proposal' => null, 'decision' => null, 'details' => [], 'extras' => []],
        ];
        $this->row('se_student_profiles', ['id' => 'sp-2', 'tenant_id' => self::TA, 'establishment_id' => $s, 'person_id' => 'p2', 'repeater' => 1, 'created_at' => '2026-09-01T08:00:00+01:00', 'updated_at' => '2026-09-01T08:00:00+01:00']);

        $classes = $this->raw('GET', '/secondary/dashboards/reports/rentree-classes?format=csv', null, 'chef', self::TA, $s);
        self::assertSame(200, $classes->getStatusCode());
        self::assertStringStartsWith('text/csv', $classes->getHeaderLine('Content-Type'));
        $body = (string) $classes->getBody();
        self::assertStringContainsString('6e A', $body);
        self::assertStringContainsString('6E;"6e A";fr;general;1;1;0;2;1;', $body, 'filles 1, garçons 1, inconnu 0, total 2, redoublant 1');

        $census = (string) $this->raw('GET', '/secondary/dashboards/reports/rentree-census', null, 'chef', self::TA, $s)->getBody();
        self::assertStringContainsString('6E;female;12-14;2', $census);
        self::assertStringContainsString('6E;unknown;unknown;1', $census);

        $annual = (string) $this->raw('GET', '/secondary/dashboards/reports/annual-levels', null, 'chef', self::TA, $s)->getBody();
        self::assertStringContainsString('6E;2;4;2;10.25;1;50', $annual);

        $xlsx = $this->raw('GET', '/secondary/dashboards/reports/objectives?format=xlsx', null, 'chef', self::TA, $s);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        [$unknown] = $this->call('GET', '/secondary/dashboards/reports/inconnu', null, 'chef', self::TA, $s);
        self::assertSame(404, $unknown);
        [$badFormat] = $this->call('GET', '/secondary/dashboards/reports/objectives?format=pdf', null, 'chef', self::TA, $s);
        self::assertSame(422, $badFormat);
        self::assertContains('secondary.dashboard.report_exported', $this->audit->actions());
    }

    public function testFournisseurDIndicateursDuSocle(): void
    {
        ['school' => $s] = $this->privateSchool();
        $indicators = $this->service(SecondaryIndicators::class)->indicators(new IndicatorScope(self::TA, $s, self::TODAY, '2026-12-07'));
        $keys = array_map(static fn ($i): string => $i->key, $indicators);
        self::assertSame(array_keys(SecondaryIndicators::EXPECTED), $keys);
        self::assertSame([], $this->service(SecondaryIndicators::class)->indicators(new IndicatorScope(self::TA, 'etablissement-inconnu', self::TODAY, '2026-12-07')));
    }
}
