<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Assessment\FixedActors;
use Tests\Support\Assessment\InMemoryEnrollments;
use Tests\Support\Assessment\InMemoryPeople;
use Tests\Support\Assessment\RecordingIssuer;
use Tests\Support\Assessment\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * TST-01 à TST-05 DE BOUT EN BOUT PAR L'API (lot E3-A, T900 à T904), données fictives : coefficients versionnés saisis dans Setup,
 * brouillon de règles du niveau (GET /secondary/councils/rule-draft) publié par POST /calculation-rules sur la structure du niveau
 * (portée classe → niveau), épreuves et notes par les routes du socle, verrouillage, conseil de classe du socle pré-rempli par le
 * fournisseur du secondaire (computed_extras, heures, conduite), validation, génération des bulletins avec les variables `secondary_*`
 * (types préréglés par POST /secondary/councils/report-card-settings/preset), aperçu par GET /secondary/councils/{id}/report-cards/{personne}.
 * Les tableaux produits sont rendus avec le vrai modèle du bulletin (migration DOC-BUL) et contrôlés par la garde du moteur.
 */
final class CouncilReportCardApiTest extends SecondaryTestCase
{
    private const YEAR = 'annee-cr-2026';
    private const CENSOR = 'censeur-cr';
    private const MANAGE = ['secondary.profile.manage', 'secondary.settings.manage', 'secondary.profile.read'];
    private const CENSOR_RIGHTS = ['assessment.read', 'assessment.enter', 'assessment.validate', 'assessment.council.manage', 'secondary.council.prepare', 'secondary.reportcard.read'];

    private InMemoryPeople $people;
    private InMemoryEnrollments $enrollments;
    private RecordingIssuer $issuer;
    private string $school = '';
    private string $class = '';
    /** @var array<string, string> code => identifiant de la matière (référentiel d'évaluation) */
    private array $subjects = [];

    protected function setUp(): void
    {
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->issuer = new RecordingIssuer();
        $this->issuer->knownTypes = ['report_card', 'class_council_minutes', 'secondary_report_trimester', 'secondary_report_annual'];
        $this->bootSecondary([
            ActorDirectory::class => new FixedActors(), PersonDirectory::class => $this->people, EnrollmentDirectory::class => $this->enrollments,
            Messenger::class => new RecordingMessenger(), DocumentIssuer::class => $this->issuer,
        ]);
        $this->school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $this->school, self::MANAGE);
        $this->grant(self::TA, self::CENSOR, $this->school, self::CENSOR_RIGHTS);
        $this->ok('POST', '/secondary/setup/profile/open', ['reason' => 'Ouverture de test'], self::ADMIN, self::TA, $this->school);
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, self::TA, $this->school), null, 'code');
        $this->class = (string) $this->ok('POST', '/secondary/setup/classes', ['label' => '4e A (fictive)', 'level_id' => $levels['4E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, self::TA, $this->school)['structure_id'];
        $this->periods();
    }

    /** Année fictive : Y > T1 (S1, S2), T2 (S3, S4), T3 (S5, S6). */
    private function periods(): void
    {
        $now = '2026-08-01T08:00:00+01:00';
        $this->pdo()->prepare('INSERT INTO academic_years (id, tenant_id, establishment_id, label, starts_on, ends_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::YEAR, self::TA, $this->school, '2026-2027', '2026-09-01', '2027-06-30', 'open', $now, $now]);
        $insert = $this->pdo()->prepare('INSERT INTO periods (id, tenant_id, establishment_id, academic_year_id, kind, ordinal, starts_on, ends_on, parent_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Y', 'year', 1, '2026-09-01', '2027-06-30', null],
            ['T1', 'trimester', 1, '2026-09-01', '2026-12-20', 'Y'], ['S1', 'sequence', 1, '2026-09-01', '2026-10-19', 'T1'], ['S2', 'sequence', 2, '2026-10-20', '2026-12-20', 'T1'],
            ['T2', 'trimester', 2, '2027-01-04', '2027-03-28', 'Y'], ['S3', 'sequence', 3, '2027-01-04', '2027-02-14', 'T2'], ['S4', 'sequence', 4, '2027-02-15', '2027-03-28', 'T2'],
            ['T3', 'trimester', 3, '2027-04-06', '2027-06-30', 'Y'], ['S5', 'sequence', 5, '2027-04-06', '2027-05-10', 'T3'], ['S6', 'sequence', 6, '2027-05-11', '2027-06-30', 'T3'],
        ] as [$id, $kind, $ordinal, $from, $to, $parent]) {
            $insert->execute([$id, self::TA, $this->school, self::YEAR, $kind, $ordinal, $from, $to, $parent, $now, $now]);
        }
    }

    /**
     * Matières et coefficients versionnés saisis dans Setup (niveau 4E), puis règles du niveau publiées depuis le brouillon.
     *
     * @param array<string, array{0: int|float, 1: string}> $subjects code => [coefficient, rôle]
     */
    private function curriculum(array $subjects): void
    {
        foreach ($subjects as $code => [$coefficient, $role]) {
            $subject = $this->ok('POST', '/secondary/setup/subjects', ['code' => strtoupper($code), 'label' => 'Matière ' . strtoupper($code) . ' (fictive)'], self::ADMIN, self::TA, $this->school);
            $this->ok('POST', '/secondary/setup/coefficients', [
                'academic_year_id' => self::YEAR, 'level_code' => '4E', 'subject_ref' => $subject['id'], 'coefficient' => $coefficient, 'weekly_hours' => 2,
                'subject_role' => $role, 'origin' => 'example', 'valid_from' => '2026-09-01', 'reason' => 'Valeur d\'exemple fictive',
            ], self::ADMIN, self::TA, $this->school);
        }
        $draft = $this->ok('GET', '/secondary/councils/rule-draft?class_id=' . $this->class . '&scope=level', null, self::CENSOR, self::TA, $this->school);
        self::assertNotSame($this->class, $draft['structure_id'], 'Portée par défaut : la structure du niveau.');
        self::assertTrue($draft['from_example']);
        foreach ($draft['coefficients'] as $line) {
            $this->subjects[strtolower((string) $line['code'])] = (string) $line['subject_id'];
        }
        self::assertCount(count($subjects), $this->subjects);
        $this->ok('POST', '/calculation-rules', ['academic_year_id' => self::YEAR, 'label' => (string) $draft['label'], 'definition' => $draft['definition'], 'structure_id' => $draft['structure_id']], self::CENSOR, self::TA, $this->school);
    }

    /** @param array<string, array{0: string, 1: string}> $students */
    private function students(array $students): void
    {
        foreach ($students as $id => [$first, $last]) {
            $this->people->add(self::TA, $id, $first, $last);
            $this->enrollments->add(self::TA, $id, $this->school, $this->class, 'SEC-FICTIF-' . strtoupper($id));
        }
    }

    /**
     * Une épreuve par matière et par séquence, notes saisies puis épreuve verrouillée (ou laissée ouverte).
     *
     * @param array<string, float|int> $marks élève => note
     */
    private function graded(string $code, string $period, array $marks, bool $lock = true): string
    {
        $assessment = $this->ok('POST', '/assessments', [
            'subject_id' => $this->subjects[$code], 'structure_id' => $this->class, 'period_id' => $period, 'academic_year_id' => self::YEAR, 'kind' => 'devoir',
            'title' => 'Devoir ' . $code . ' ' . $period, 'weight' => 1.0, 'max_score' => 20.0,
        ], self::CENSOR, self::TA, $this->school);
        foreach ($marks as $person => $value) {
            $this->ok('POST', '/grades', ['assessment_id' => $assessment['id'], 'person_id' => $person, 'value' => $value], self::CENSOR, self::TA, $this->school);
        }
        if ($lock) {
            foreach (['entry', 'locked'] as $state) {
                $this->ok('POST', '/assessments/' . $assessment['id'] . '/transition', ['to' => $state], self::CENSOR, self::TA, $this->school);
            }
        }

        return (string) $assessment['id'];
    }

    /** @return array<string, mixed> conseil ouvert */
    private function council(string $period, string $label): array
    {
        return $this->ok('POST', '/class-councils', ['structure_id' => $this->class, 'period_id' => $period, 'period_label' => $label], self::CENSOR, self::TA, $this->school);
    }

    /** @return array<string, array<string, mixed>> */
    private static function lines(array $council): array
    {
        $lines = [];
        foreach ($council['lines'] as $line) {
            $line['line_extras'] = is_string($line['line_extras'] ?? null) ? json_decode($line['line_extras'], true) : ($line['line_extras'] ?? []);
            $lines[(string) $line['person_id']] = $line;
        }

        return $lines;
    }

    /** @return array<string, array<string, mixed>> type => données émises, par élève */
    private function issue(string $councilId): array
    {
        $before = count($this->issuer->issued);
        $this->ok('POST', '/class-councils/' . $councilId . '/validate', [], self::CENSOR, self::TA, $this->school);
        $cards = $this->ok('POST', '/class-councils/' . $councilId . '/report-cards', [], self::CENSOR, self::TA, $this->school);
        foreach ($cards as $card) {
            self::assertSame('issued', $card['status'], (string) json_encode($card, JSON_UNESCAPED_UNICODE));
        }
        $out = [];
        foreach (array_slice($this->issuer->issued, $before) as $issued) {
            $out[$issued['person']] = $issued;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function table(array $data, string $name): array
    {
        return json_decode((string) $data[$name], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Rend les variables avec le vrai modèle FR du type (migration DOC-BUL) : garde du moteur et tableaux bien formés. */
    private function render(string $type, array $data): string
    {
        $row = $this->pdo()->query("SELECT html FROM document_templates WHERE type_code = '" . $type . "' AND locale = 'fr' ORDER BY version DESC LIMIT 1")->fetch();
        $declared = json_decode((string) $this->pdo()->query("SELECT variables FROM document_types WHERE code = '" . $type . "'")->fetchColumn(), true);
        self::assertIsArray($row);
        self::assertIsArray($declared);
        self::assertSame([], array_values(array_diff(array_keys($data), $declared)), 'Toutes les variables produites sont déclarées par le type.');
        TemplateGuard::assertValid((string) $row['html'], $declared);

        $data = array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, $data);

        return TemplateRenderer::render((string) $row['html'], $data + ['number' => 'N-FICTIF', 'verification_url' => 'https://verif.exemple.invalid/v/X', 'holder_name' => 'Élève fictif',
            'establishment_name' => 'Lycée fictif', 'issued_on' => '20/12/2026', 'year' => '2026'], ['qr' => '', 'signature' => '', 'stamp' => '', 'logo' => '']);
    }

    public function testTst01Tst04Tst05TrimestreDAichaDeBoutEnBout(): void
    {
        $this->curriculum(array_map(static fn (array $row): array => [$row[0], 'official'], AichaTrimesterTest::SUBJECTS));
        $this->students(['aicha' => ['Aïcha', 'Mballa'], 'eleve-b' => ['Bertin', 'Owona'], 'eleve-c' => ['Carine', 'Ngo'], 'eleve-d' => ['Daouda', 'Sali']]);
        self::assertTrue($this->ok('POST', '/secondary/councils/report-card-settings/preset', [], self::ADMIN, self::TA, $this->school)['applied']);

        $open = null;
        foreach (AichaTrimesterTest::SUBJECTS as $code => [, $s1, $s2]) {
            // élèves b et c ex æquo (12 partout), d à 9 : rangs 1 (b, c ex æquo) ... voir plus bas.
            $this->graded($code, 'S1', ['aicha' => $s1, 'eleve-b' => 12, 'eleve-c' => 12, 'eleve-d' => 9]);
            $last = $code === 'eps';
            $id = $this->graded($code, 'S2', ['aicha' => $s2, 'eleve-b' => 12, 'eleve-c' => 12, 'eleve-d' => 9], !$last);
            $open = $last ? $id : $open;
        }

        $council = $this->council('T1', '1er trimestre');
        $lines = self::lines($council);
        self::assertEquals(11.30, $lines['aicha']['average'], 'TST-01 : moyenne 11,30.');
        self::assertSame([1, 1, 3, 4], [$lines['eleve-b']['rank_in_class'], $lines['eleve-c']['rank_in_class'], $lines['aicha']['rank_in_class'], $lines['eleve-d']['rank_in_class']], 'TST-04 : ex æquo au même rang, le suivant décalé.');
        $extras = $lines['aicha']['line_extras'];
        self::assertSame([$this->subjects['math']], $extras['subject_alerts'], 'TST-01 : seule la matière à 7,75 est signalée (computed_extras repris).');
        self::assertNull($extras['work_proposal'], 'TST-01 : aucune proposition du travail.');
        self::assertFalse($extras['distinction_refused']);
        self::assertEquals(['total' => 0, 'justified' => 0, 'unjustified' => 0, 'lates' => 0], $extras['absence_hours']);
        self::assertSame([], $extras['conduct_sanctions']);
        self::assertNull($extras['workshop_practical'], 'Aucun contrat Workshops : colonne pratique vide.');
        self::assertSame('Tableau d\'honneur', $lines['eleve-b']['line_extras']['work_proposal']['label'], '12 de moyenne : tranche d\'exemple « 12 à moins de 14 ».');

        [$status] = $this->call('POST', '/class-councils/' . $council['id'] . '/validate', [], self::CENSOR, self::TA, $this->school);
        self::assertSame(409, $status, 'TST-05 : une épreuve non verrouillée, conseil (et bulletins) refusés.');
        foreach (['entry', 'locked'] as $state) {
            $this->ok('POST', '/assessments/' . $open . '/transition', ['to' => $state], self::CENSOR, self::TA, $this->school);
        }

        $issued = $this->issue((string) $council['id']);
        self::assertSame('secondary_report_trimester', $issued['aicha']['type'], 'Type préréglé par nature de période.');
        $data = $issued['aicha']['data'];
        self::assertSame('4e A (fictive)', $data['secondary_class_name']);
        self::assertSame('MBALLA', $data['secondary_last_name']);
        self::assertSame('SEC-FICTIF-AICHA', $data['secondary_matricule']);
        self::assertSame('2026-2027', $data['secondary_academic_year']);
        self::assertSame('', $data['secondary_council_decision'], 'Bulletin trimestriel : pas de décision de passage.');

        $subjects = self::table($data, 'secondary_subjects_table');
        self::assertSame(['SEQ1', 'SEQ2', 'TRIM1', 'SEQ3', 'SEQ4', 'TRIM2', 'SEQ5', 'SEQ6', 'TRIM3'], $subjects['head'][1]);
        $math = null;
        foreach ($subjects['rows'] as $row) {
            if (is_array($row) && array_is_list($row) && str_starts_with((string) $row[0], 'Matière MATH')) {
                $math = $row;
            }
        }
        self::assertSame(['4,00', '8,50', '7,00', '7,75', '', '', '', '', '', ''], array_slice($math, 1, 10), 'Coefficient de Setup, SEQ1, SEQ2, TRIM1, périodes suivantes vides.');
        self::assertSame('4e', $math[12], 'Rang dans la matière au trimestre.');
        $total = end($subjects['rows']);
        self::assertSame('total', $total['kind']);
        self::assertSame(['TOTAL 1', '22,00'], array_slice($total['cells'], 0, 2));
        self::assertSame('11,30', $total['cells'][4]);

        $summary = self::table($data, 'secondary_summary_table');
        self::assertSame(['Total', '248,50'], [$summary['rows'][0][0], $summary['rows'][0][3]], 'TST-01 : total 248,50 au trimestre.');
        self::assertSame('11,30', $summary['rows'][1][3]);
        self::assertSame('3e', $summary['rows'][2][3]);
        $profile = self::table($data, 'secondary_class_profile_table');
        self::assertSame(['Moyenne du 1er', '12,00', '', ''], $profile['rows'][0]);
        $work = self::table($data, 'secondary_work_table');
        self::assertSame(['Tableau d\'honneur', 'Non'], $work['rows'][2]);
        self::assertSame(['Tableau d\'honneur', 'Oui'], self::table($issued['eleve-b']['data'], 'secondary_work_table')['rows'][2]);

        $html = $this->render('secondary_report_trimester', $data + ['structure' => $this->class, 'period' => '1er trimestre', 'average' => '11,30', 'rank' => '3', 'class_size' => '4', 'decision' => '', 'appraisal' => '']);
        self::assertStringContainsString('<table class="doc-table', $html);
        self::assertStringContainsString('248,50', $html);

        $preview = $this->ok('GET', '/secondary/councils/' . $council['id'] . '/report-cards/aicha', null, self::CENSOR, self::TA, $this->school);
        self::assertSame($data['secondary_summary_table'], $preview['variables']['secondary_summary_table']);
        self::assertSame($data['secondary_summary_table'], $preview['snapshot']['variables']['secondary_summary_table'], 'Instantané enregistré à l\'émission.');
        $this->grant(self::TB, self::CENSOR, $this->school, self::CENSOR_RIGHTS);
        self::assertSame(404, $this->call('GET', '/secondary/councils/' . $council['id'] . '/report-cards/aicha', null, self::CENSOR, self::TB, $this->school)[0], 'Autre client : introuvable.');
        self::assertSame(403, $this->call('GET', '/secondary/councils/' . $council['id'] . '/report-cards/aicha', null, self::ADMIN, self::TA, $this->school)[0], 'Sans droit de lecture des bulletins.');
    }

    public function testTst02AnneeDAichaAdmiseEtBulletinAnnuel(): void
    {
        $this->curriculum(array_map(static fn (array $row): array => [$row[0], 'official'], AichaTrimesterTest::SUBJECTS));
        $this->students(['aicha' => ['Aïcha', 'Mballa'], 'eleve-b' => ['Bertin', 'Owona']]);
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->school, 'assessment.report_card_types', ['trimester' => 'secondary_report_trimester', 'year' => 'secondary_report_annual'], self::ADMIN);
        foreach (AichaTrimesterTest::SUBJECTS as $code => [, $s1, $s2]) {
            foreach (['S1' => $s1, 'S2' => $s2, 'S3' => 11.2, 'S4' => 11.2, 'S5' => 12.05, 'S6' => 12.05] as $period => $mark) {
                $this->graded($code, $period, ['aicha' => $mark, 'eleve-b' => 8]);
            }
        }

        $council = $this->council('Y', 'Année 2026-2027');
        $line = self::lines($council)['aicha'];
        self::assertEquals(11.52, $line['average'], 'TST-02 : (11,30 + 11,20 + 12,05) ÷ 3.');
        self::assertSame('admitted', $line['line_extras']['promotion']['code']);
        self::assertSame('Admis en classe supérieure', $line['line_extras']['promotion']['label']);
        self::assertSame('repeat', self::lines($council)['eleve-b']['line_extras']['promotion']['code'], '8 de moyenne, sous le seuil de réexamen d\'exemple (9).');
        if ($line['decision'] !== 'Admis en classe supérieure') {
            $this->ok('PATCH', '/class-councils/' . $council['id'] . '/lines/' . $line['id'], ['decision' => 'Admis en classe supérieure', 'reason' => 'Proposition de passage du secondaire'], self::CENSOR, self::TA, $this->school);
        }

        $issued = $this->issue((string) $council['id']);
        self::assertSame('secondary_report_annual', $issued['aicha']['type']);
        $data = $issued['aicha']['data'];
        self::assertSame('Admis en classe supérieure', $data['secondary_council_decision']);
        $summary = self::table($data, 'secondary_summary_table');
        self::assertSame(['11,30', '11,20', '12,05'], [$summary['rows'][1][3], $summary['rows'][1][6], $summary['rows'][1][9]]);
        $profile = self::table($data, 'secondary_class_profile_table');
        self::assertSame(['Moyenne du 1er', '11,30', '11,20', '12,05', '11,52'], $profile['rows'][0]);
        $html = $this->render('secondary_report_annual', $data + ['structure' => $this->class, 'period' => 'Année', 'average' => '11,52', 'rank' => '1', 'class_size' => '2', 'decision' => 'admitted', 'appraisal' => '']);
        self::assertStringContainsString('Admis en classe supérieure', $html);
    }

    public function testTst03PratiqueSousLeSeuilEnFinDAnnee(): void
    {
        $curriculum = [];
        foreach (BriceCetic3Test::SUBJECTS as $code => $row) {
            $curriculum[$code] = [$row[0], $code === 'atelier' ? 'practical' : 'official'];
        }
        $this->curriculum($curriculum);
        $this->students(['brice' => ['Brice', 'Tchana'], 'eleve-b' => ['Bertin', 'Owona']]);
        foreach (BriceCetic3Test::SUBJECTS as $code => [, $s1, $s2]) {
            foreach (['S1' => $s1, 'S2' => $s2, 'S3' => $s1, 'S4' => $s2, 'S5' => $s1, 'S6' => $s2] as $period => $mark) {
                $this->graded($code, $period, ['brice' => $mark, 'eleve-b' => 12]);
            }
        }

        $trimester = self::lines($this->council('T1', '1er trimestre'))['brice'];
        self::assertEquals(10.07, $trimester['average'], 'TST-03 : 221,50 ÷ 22.');
        self::assertSame([$this->subjects['atelier']], $trimester['line_extras']['practical_alerts'], 'Rôle « practical » de Setup porté par les règles du niveau.');

        $year = self::lines($this->council('Y', 'Année'))['brice'];
        self::assertSame('practical_refused', $year['line_extras']['promotion']['code'], 'Refus proposé : pratique annuelle sous le seuil d\'exemple.');
        self::assertTrue($year['line_extras']['promotion']['conditional_possible'], 'Passage conditionnel possible.');
    }
}
