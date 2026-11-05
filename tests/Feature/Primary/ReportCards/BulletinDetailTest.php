<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\ReportCards;

use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\NumberedEnrollments;
use Tests\Support\Primary\PrimaryTestCase;

/**
 * C-104 : bulletin complet du primaire privé (modèle B). Notes SEQ1/SEQ2, MOY, COEF, TOTAL, RANG par matière, totaux par domaine, profil
 * de la classe, bloc Travail (seuils paramétrés « à confirmer »), date de naissance et matricule, à partir des seules données existantes ;
 * une donnée absente n'est pas affichée. Données fictives ; le modèle rendu tient sur une page A4.
 */
final class BulletinDetailTest extends PrimaryTestCase
{
    private FakeClassResults $results;
    private NumberedEnrollments $enrollments;
    /** @var array<int, array{id: string, starts_on: string, ends_on: string, sequences: list<array{id: string, starts_on: string}>}> */
    private array $terms;
    private string $class;
    private string $level;
    /** @var array<string, string> code de matière => identifiant */
    private array $subjects = [];

    protected function setUp(): void
    {
        $this->results = new FakeClassResults();
        $this->enrollments = new NumberedEnrollments();
        $people = new InMemoryPeople();
        $demographics = new class () implements PersonDemographics {
            public function ofPersons(string $tenantId, array $personIds): array
            {
                return array_fill_keys(array_intersect($personIds, ['e1', 'e2']), ['gender' => 'female', 'birth_date' => '2017-04-12']);
            }
        };
        $this->bootPrimary([
            EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $people, ClassResults::class => $this->results, PersonDemographics::class => $demographics,
        ]);
        $this->terms = $this->seedYear(self::TA, self::EA);
        $this->ok('POST', '/primary/referentials/example/apply', ['academic_year_id' => self::YEAR]);
        $profiles = $this->call('GET', '/primary/level-profiles?sort=sort_order')[1]['data'];
        $this->level = $profiles[0]['level_structure_id'];
        $this->class = $this->service(OrgUnitTree::class)->structureDescendantsOf(self::TA, self::EA, $this->level)[1];
        foreach (['e1' => 'IPR-0001', 'e2' => null, 'e3' => null] as $id => $matricule) {
            $people->add(self::TA, $id, 'Prénom' . $id, 'Nom' . $id);
            $this->enrollments->add(self::TA, 'en' . $id, $id, self::EA, $this->class, self::YEAR, $matricule);
        }
        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $statement = $pdo->prepare('SELECT subject_id, subject_code FROM pri_level_subjects WHERE tenant_id = ? AND level_structure_id = ?');
        $statement->execute([self::TA, $this->level]);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->subjects[(string) $row['subject_code']] = (string) $row['subject_id'];
        }
        $catalog = $this->service(CurriculumCatalog::class);
        foreach (self::COEFFICIENTS as $code => $coefficient) {
            $catalog->upsertSubject(self::TA, self::EA, new CurriculumItem('pri:' . $code, $code, 'Matière ' . $code, 'subject', $coefficient, 0));
        }
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.council.require_locked_sheets', false, self::DIRECTOR);
    }

    /** Coefficients du référentiel fictif (catalogue du socle) ; une autre matière prend le coefficient par défaut. */
    private const COEFFICIENTS = ['LECT' => 2.0, 'CALC' => 3.0, 'EDUC' => 1.0];

    /**
     * Notes fictives ; la doublure du moteur pondère chaque matière comme le socle (coefficient du référentiel, sinon celui par défaut).
     *
     * @param array<string, float> $marks code => note
     */
    private function marks(string $periodId, string $person, array $marks, float $defaultCoefficient = 1.0): void
    {
        $rows = [];
        foreach ($marks as $code => $value) {
            $rows[] = ['subject_id' => $this->subjects[$code] ?? ('sub-' . $code), 'code' => $code, 'name' => 'Matière ' . $code, 'average' => $value, 'coefficient' => self::COEFFICIENTS[$code] ?? $defaultCoefficient];
        }
        $this->results->subjects[$periodId][$person] = $rows;
        $this->results->averages[$periodId][$person] = null;
    }

    private function openCouncil(): string
    {
        $t1 = $this->terms[1];

        return (string) $this->ok('POST', '/primary/councils', ['class_id' => $this->class, 'period_id' => $t1['id'], 'starts_on' => $t1['starts_on'], 'period_label' => 'Trimestre 1', 'held_on' => '2026-11-26', 'sequences' => $t1['sequences']])['id'];
    }

    private function seedMarks(): void
    {
        $t1 = $this->terms[1];
        [$s1, $s2] = [$t1['sequences'][0]['id'], $t1['sequences'][1]['id']];
        $this->marks($s1, 'e1', ['LECT' => 15.0, 'CALC' => 11.0, 'EDUC' => 16.0]);
        $this->marks($s2, 'e1', ['LECT' => 13.0, 'CALC' => 13.0, 'EDUC' => 16.0]);
        $this->marks($t1['id'], 'e1', ['LECT' => 14.0, 'CALC' => 12.0, 'EDUC' => 16.0]);
        $this->marks($t1['id'], 'e2', ['LECT' => 12.0, 'CALC' => 12.0, 'EDUC' => 12.0]);
        $this->marks($t1['id'], 'e3', ['LECT' => 10.0, 'CALC' => 8.0, 'EDUC' => 12.0]);
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
    }

    /** @return array<string, mixed> */
    private function preview(string $council, string $person = 'e1'): array
    {
        return $this->ok('GET', "/primary/councils/$council/report-cards/preview?person_id=$person");
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private static function table(array $preview, string $variable): array
    {
        return json_decode((string) $preview['variables'][$variable], true);
    }

    public function testNotesParSequenceMoyenneCoefficientTotalEtRangParMatiere(): void
    {
        $this->seedMarks();
        $preview = $this->preview($this->openCouncil());
        $table = self::table($preview, 'subjects_detail_table');

        self::assertSame(['SEQ1', 'SEQ2', 'MOY', 'COEF', 'TOTAL'], array_slice(array_column($table['columns'], 'label'), 1, 5));
        self::assertSame(7, count($table['columns']), 'Matière, SEQ1, SEQ2, MOY, COEF, TOTAL, RANG.');
        $lecture = $table['rows'][0];
        self::assertSame(['Matière LECT', '15,00', '13,00', '14,00', '2', '28,00', '1'], $lecture, 'e1 est première en lecture (14 / 12 / 10).');
        $calcul = $table['rows'][1];
        self::assertSame(['Matière CALC', '11,00', '13,00', '12,00', '3', '36,00', '1'], array_map('strval', $calcul), 'e1 et e2 ex æquo à 12 : même rang.');
        $total = end($table['rows']);
        self::assertSame('total', $total['kind']);
        self::assertSame(['13,33', '6', '80,00'], [$total['cells'][1], $total['cells'][2], $total['cells'][3]], 'Somme des coefficients 2 + 3 + 1, des totaux 28 + 36 + 16, moyenne 80 / 6.');
    }

    public function testLesColonnesAbsentesNeSontPasInventees(): void
    {
        $t1 = $this->terms[1];
        $this->marks($t1['id'], 'e1', ['LECT' => 14.0, 'CALC' => 12.0]);
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
        $preview = $this->preview($this->openCouncil());
        $table = self::table($preview, 'subjects_detail_table');

        self::assertNotContains('SEQ1', array_column($table['columns'], 'label'), 'Aucune note de séquence : pas de colonne SEQ.');
        self::assertContains('MOY', array_column($table['columns'], 'label'));
        $snapshot = $preview['snapshot']['detail'];
        self::assertSame([], $snapshot['sequences']);
        self::assertNotContains('COMP', array_column($table['columns'], 'label'), 'Aucune source de composition : jamais de colonne COMP.');
    }

    public function testTotauxParDomaineEtProfilDeLaClasse(): void
    {
        $this->seedMarks();
        $preview = $this->preview($this->openCouncil());
        $domains = self::table($preview, 'domain_totals_table')['rows'];

        // LECT (2 x 14 = 28) en LANG ; CALC (3 x 12 = 36) en MATH ; EDUC (1 x 16 = 16) en SOC.
        self::assertSame([['2', '28,00', '14,00'], ['3', '36,00', '12,00'], ['1', '16,00', '16,00']], array_map(static fn (array $r): array => array_slice($r, 1), $domains));
        $profile = $preview['snapshot']['detail']['class_profile'];
        self::assertEquals(['size' => 3, 'above_pass' => 2, 'class_average' => 11.55, 'max' => 13.33, 'min' => 9.33], array_intersect_key($profile, array_flip(['size', 'above_pass', 'class_average', 'max', 'min'])));
        self::assertEquals(66.7, $profile['success_rate'], 'Moyennes pondérées 13,33 / 12 / 9,33 : deux sur trois au-dessus de 10.');
        $rows = array_column(self::table($preview, 'class_profile_table')['rows'], 1);
        self::assertContains('2 / 3', $rows);
        self::assertContains('11,55', $rows);
        self::assertContains('66,7 %', $rows);
    }

    public function testBlocTravailSelonLesSeuilsParametresEtRienSansSeuil(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();
        $without = self::table($this->preview($council), 'work_table');
        self::assertSame('muted', $without['rows'][0]['kind'], 'Sans seuil paramétré, aucune distinction n\'est affichée.');

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.distinctions', [
            'honour_roll_min' => 13, 'congratulations_min' => 15, 'encouragements_min' => 12, 'warning_max' => 9, 'blame_max' => 8,
        ], self::DIRECTOR);
        $work = self::table($this->preview($council, 'e1'), 'work_table')['rows'];
        $byLabel = [];
        foreach ($work as $row) {
            $byLabel[explode(' / ', (string) $row[0])[0]] = $row[1];
        }
        self::assertSame(['Tableau d\'honneur' => 'Oui', 'Félicitations' => 'Non', 'Encouragements' => 'Oui', 'Avertissement' => 'Non', 'Blâme' => 'Non'], $byLabel, 'Moyenne 13,33 : honneur et encouragements.');
        $e3 = [];
        foreach (self::table($this->preview($council, 'e3'), 'work_table')['rows'] as $row) {
            $e3[explode(' / ', (string) $row[0])[0]] = $row[1];
        }
        self::assertSame(['Non', 'Non'], [$e3['Avertissement'], $e3['Blâme']], 'Moyenne 9,33 : ni sous 9 ni sous 8.');
    }

    public function testMoyenneGeneraleCoherenteAvecCoefficientsMixtesEtCoefficientParDefaut(): void
    {
        // ART et MUSI n'ont pas de coefficient au référentiel : coefficient par défaut de l'école (2, paramètre « à confirmer »).
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.default_coefficient', 2, self::DIRECTOR);
        $t1 = $this->terms[1];
        $this->marks($t1['id'], 'e1', ['LECT' => 14.0, 'CALC' => 12.0, 'EDUC' => 16.0, 'ART' => 15.0, 'MUSI' => 9.5], 2.0);
        $this->marks($t1['id'], 'e2', ['LECT' => 16.0, 'CALC' => 15.0, 'EDUC' => 14.0, 'ART' => 15.0, 'MUSI' => 13.0], 2.0);
        $this->marks($t1['id'], 'e3', ['LECT' => 10.0, 'CALC' => 9.0, 'EDUC' => 12.0, 'ART' => 11.0, 'MUSI' => 10.0], 2.0);
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
        $preview = $this->preview($this->openCouncil(), 'e1');
        $table = self::table($preview, 'subjects_detail_table');
        $rows = $table['rows'];
        $total = array_pop($rows);
        $labels = array_column($table['columns'], 'label');
        [$moy, $coef, $tot] = [(int) array_search('MOY', $labels, true), (int) array_search('COEF', $labels, true), (int) array_search('TOTAL', $labels, true)];
        self::assertSame([1, 2, 3], [$moy, $coef, $tot], 'Colonnes : matière, MOY, COEF, TOTAL (aucune note de séquence).');
        $num = static fn (string $v): float => (float) str_replace(',', '.', $v);

        self::assertNotContains('—', array_column($rows, $coef), 'Chaque matière notée a un coefficient (référentiel ou défaut).');
        self::assertSame(['2', '2'], [$rows[3][$coef], $rows[4][$coef]], 'Coefficient par défaut de l\'école pour ART et MUSI.');
        $sumCoef = 0.0;
        $sumTotal = 0.0;
        foreach ($rows as $row) {
            self::assertEqualsWithDelta($num($row[$moy]) * $num($row[$coef]), $num($row[$tot]), 0.001, 'TOTAL = MOY × COEF pour ' . $row[0]);
            $sumCoef += $num($row[$coef]);
            $sumTotal += $num($row[$tot]);
        }
        // Ligne de total : [libellé, MOY, COEF, TOTAL, RANG].
        self::assertSame(['10', '129,00'], [$total['cells'][2], $total['cells'][3]], '2 + 3 + 1 + 2 + 2 ; 28 + 36 + 16 + 30 + 19.');
        self::assertEqualsWithDelta($sumTotal, $num($total['cells'][3]), 0.001, 'TOTAL = Σ(MOY × COEF).');
        self::assertEqualsWithDelta($sumCoef, $num($total['cells'][2]), 0.001);
        self::assertSame('12,90', $total['cells'][1], 'Moyenne générale = TOTAL ÷ ΣCOEF = 129 / 10.');
        self::assertSame(number_format($sumTotal / $sumCoef, 2, ',', ''), $total['cells'][1]);
        self::assertSame('Moyenne générale : 12,90 / 20,00', $preview['variables']['average_line'], 'Bloc « Résultat de l\'élève » : même valeur.');
        self::assertEquals(12.9, $preview['snapshot']['numeric']['average']);
        self::assertSame('2', $total['cells'][4], 'Rang calculé sur la même moyenne (e2 : 147 / 10 = 14,70 devant).');
        self::assertSame('Rang : 2e sur 3', $preview['variables']['rank_line']);
        $profile = $preview['snapshot']['detail']['class_profile'];
        self::assertEquals(['max' => 14.7, 'min' => 10.1], ['max' => $profile['max'], 'min' => $profile['min']], 'Profil de la classe sur les mêmes moyennes pondérées.');
    }

    public function testDateDeNaissanceEtMatriculeSeulementQuandIlsExistent(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();

        self::assertSame('Né(e) le 12/04/2017 · Matricule IPR-0001', $this->preview($council, 'e1')['variables']['identity_line']);
        self::assertSame('Né(e) le 12/04/2017', $this->preview($council, 'e2')['variables']['identity_line'], 'Pas de matricule : non affiché.');
        self::assertSame('', $this->preview($council, 'e3')['variables']['identity_line'], 'Ni date ni matricule : rien.');
    }

    public function testBulletinEmisAvecLeDetailEtModeleSurUnePageA4(): void
    {
        $this->seedMarks();
        $council = $this->openCouncil();
        $this->ok('POST', "/primary/councils/$council/validate");
        $issued = $this->ok('POST', "/primary/councils/$council/report-cards/issue");
        self::assertSame(0, $issued['failed']);
        $card = array_values(array_filter($issued['items'], static fn (array $c): bool => $c['person_id'] === 'e1'))[0];
        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $content = (string) $pdo->query("SELECT content FROM documents WHERE id = '" . $card['document_id'] . "'")->fetchColumn();
        foreach (['SEQ1', 'SEQ2', 'COEF', 'TOTAL', 'Matière LECT', 'IPR-0001', 'Profil de la classe'] as $needle) {
            self::assertStringContainsString($needle, $content, $needle);
        }
        self::assertStringNotContainsString('{{', $content);
    }

    /**
     * Exemple complet (dix matières, trois élèves fictifs) : les notes existent AVANT l'ouverture du conseil, donc la moyenne générale du
     * tableau, celle du bloc résultat, le rang et le profil de la classe viennent du même calcul. Rendu sur une seule page A4 ;
     * DOC_BUL_EXAMPLES=1 régénère specs/006-audit-primaire-maternelle/exemples-documents/pri_report_card-fr-complet.pdf.
     */
    public function testExempleCompletCoherentSurUnePageA4(): void
    {
        $t1 = $this->terms[1];
        $ten = ['LECT' => 14.0, 'CALC' => 12.0, 'EDUC' => 16.0, 'DESS' => 15.0, 'MUSI' => 13.0, 'SPOR' => 17.0, 'HIST' => 11.0, 'GEOG' => 12.5, 'SCIE' => 14.5, 'INFO' => 16.5];
        $shift = static fn (float $delta, float $seq): array => array_map(static fn (float $v): float => max(0.0, min(20.0, $v + $delta + $seq)), $ten);
        foreach (['e1' => 0.0, 'e2' => 1.0, 'e3' => -2.0] as $person => $delta) {
            $this->marks($t1['sequences'][0]['id'], $person, $shift($delta, -0.5));
            $this->marks($t1['sequences'][1]['id'], $person, $shift($delta, 0.5));
            $this->marks($t1['id'], $person, $shift($delta, 0.0));
        }
        $this->results->roster[$this->class] = ['e1', 'e2', 'e3'];
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.distinctions', ['honour_roll_min' => 14, 'blame_max' => 5], self::DIRECTOR);
        $preview = $this->preview($this->openCouncil());
        $table = self::table($preview, 'subjects_detail_table');
        $total = end($table['rows']);
        // 7 matières au coefficient par défaut 1 + LECT 2, CALC 3, EDUC 1 : ΣCOEF 13 ; TOTAL 28 + 36 + 16 + 99,5 = 179,5 ; 179,5 / 13 = 13,81.
        self::assertSame(['13,81', '13', '179,50', '2'], array_slice($total['cells'], 1, 4), 'MOY = TOTAL ÷ ΣCOEF ; e2 (192,5 / 13 = 14,81) est première.');
        self::assertSame('Moyenne générale : 13,81 / 20,00', $preview['variables']['average_line']);
        self::assertSame('Rang : 2e sur 3', $preview['variables']['rank_line']);
        $profile = $preview['snapshot']['detail']['class_profile'];
        self::assertEquals([14.81, 11.81], [$profile['max'], $profile['min']], 'Max et min encadrent la moyenne de l\'élève.');

        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        $variables = array_map(static fn (mixed $v): string => (string) $v, $preview['variables']);
        $type = $pdo->query("SELECT variables FROM document_types WHERE tenant_id = '*' AND code = 'pri_report_card'")->fetchColumn();
        $html = (string) $pdo->query("SELECT html FROM document_templates WHERE tenant_id = '*' AND type_code = 'pri_report_card' AND locale = 'fr' ORDER BY version DESC LIMIT 1")->fetchColumn();
        TemplateGuard::assertValid($html, json_decode((string) $type, true));
        $page = TemplateRenderer::render($html, $variables + [
            'number' => 'ECP-26-B-000042', 'verification_url' => 'https://verif.scholaris.example/v/EXEMPLEFICTIF', 'holder_name' => 'MBARGA ESSOMBA Nadège (fictif)',
            'establishment_name' => 'École privée Les Flamboyants (fictive)', 'issued_on' => '31/10/2026', 'year' => '2026',
        ], ['qr' => (new LibraryQrCodeGenerator())->markup('https://verif.scholaris.example/v/EXEMPLEFICTIF'), 'signature' => '', 'stamp' => '', 'logo' => '']);
        $pdf = (new DompdfRenderer(sys_get_temp_dir() . '/scholaris-dompdf-test'))->render(DocumentService::withPrintStyle($page));
        self::assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Une seule page A4 avec dix matières.');
        if (getenv('DOC_BUL_EXAMPLES') === '1') {
            $dir = dirname(__DIR__, 4) . '/specs/006-audit-primaire-maternelle/exemples-documents';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/pri_report_card-fr-complet.pdf', $pdf);
            file_put_contents(sys_get_temp_dir() . '/doc-bul-pri_report_card-fr-complet.html', DocumentService::withPrintStyle($page));
        }
    }

    public function testLeBulletinSansMoyennesChiffreesNAffichePasLeDetail(): void
    {
        $this->seedMarks();
        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, self::EA, 'primary.report_card.numeric_averages', false, self::DIRECTOR);
        $preview = $this->preview($this->openCouncil());

        self::assertSame([], $preview['snapshot']['detail']['subjects']);
        self::assertSame('muted', self::table($preview, 'subjects_detail_table')['rows'][0]['kind']);
        self::assertSame('Né(e) le 12/04/2017 · Matricule IPR-0001', $preview['variables']['identity_line']);
    }
}
