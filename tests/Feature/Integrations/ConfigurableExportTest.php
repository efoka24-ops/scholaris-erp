<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\XlsxWriter;
use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Tests\Support\AppTestCase;
use Tests\Support\Billing\ArraySettings;

/** S14 : export tutelle / MINESUP à colonnes configurables par type d'export, en CSV et XLSX. */
final class ConfigurableExportTest extends AppTestCase
{
    private const FIELDS = ['matricule', 'last_name', 'first_names', 'birth_date', 'sex', 'specialty', 'academic_year'];
    private const RECORDS = [
        ['matricule' => '26EC-CF-0042', 'last_name' => 'Ngo', 'first_names' => 'Marie', 'birth_date' => '2005-03-14', 'sex' => 'F', 'specialty' => 'Comptabilité', 'academic_year' => '2026-2027'],
        ['matricule' => '26EC-CF-0043', 'last_name' => '=Piège', 'first_names' => 'Paul', 'birth_date' => '2004-11-02', 'sex' => 'M', 'specialty' => 'Comptabilité', 'academic_year' => '2026-2027'],
    ];

    private ArraySettings $settings;

    protected function setUp(): void
    {
        $this->settings = new ArraySettings();
        $this->boot([
            SettingsReader::class => $this->settings,
            // Les types de test portent les clés des types réels de Higher\NationalExams : on neutralise ceux-ci.
            'integrations.export_types.higher_nationalexams' => [],
            'integrations.export_types.higher' => [
                new ConfiguredExportType('minesup.bts', 'Liste MINESUP BTS', self::FIELDS, [
                    'Matricule' => 'matricule', 'Nom' => 'last_name', 'Prénoms' => 'first_names', 'Naissance' => 'birth_date',
                    'Sexe' => 'sex', 'Spécialité' => 'specialty', 'Année' => 'academic_year',
                ], 'higher.minesup.export.bts.columns'),
                new ConfiguredExportType('tutelle.uy2', 'Inscrits UY2', self::FIELDS, ['matricule' => 'matricule', 'nom' => 'last_name']),
            ],
        ]);
    }

    private function exporter(): TabularExporter
    {
        return $this->kernel->container()->get(TabularExporter::class);
    }

    public function testColonnesParDefautEnCsv(): void
    {
        $file = $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS);

        self::assertSame('minesup.bts.csv', $file->filename);
        self::assertSame(2, $file->rows);
        self::assertStringStartsWith("\xEF\xBB\xBFMatricule;Nom;Prénoms;Naissance;Sexe;Spécialité;Année\n", $file->content);
        self::assertStringContainsString('26EC-CF-0042;Ngo;Marie;2005-03-14;F;Comptabilité;2026-2027', $file->content);
        self::assertStringContainsString("'=Piège", $file->content, 'Cellule neutralisée contre l\'injection de formule.');
    }

    public function testColonnesParametreesParEtablissementEtXlsx(): void
    {
        $this->settings->values['higher.minesup.export.bts.columns'] = ['NOM' => 'last_name', 'MATRICULE' => 'matricule'];
        self::assertSame(['NOM' => 'last_name', 'MATRICULE' => 'matricule'], $this->exporter()->columns('t1', 'e1', 'minesup.bts'));

        $csv = $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS);
        self::assertSame(['NOM', 'MATRICULE'], $csv->columns);
        self::assertStringContainsString('Ngo;26EC-CF-0042', $csv->content);

        $xlsx = $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS, 'xlsx');
        self::assertSame(XlsxWriter::CONTENT_TYPE, $xlsx->contentType);
        self::assertSame('minesup.bts.xlsx', $xlsx->filename);
        self::assertStringStartsWith('PK', $xlsx->content);

        // Liste de champs : l'en-tête est le nom du champ ; un type sans paramètre garde ses colonnes.
        $this->settings->values['higher.minesup.export.bts.columns'] = ['matricule', 'sex'];
        self::assertSame(['matricule', 'sex'], $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS)->columns);
        self::assertSame(['matricule', 'nom'], $this->exporter()->render('t1', 'e1', 'tutelle.uy2', self::RECORDS, 'json')->columns);
    }

    public function testErreursDeTypeDeFormatEtDeParametre(): void
    {
        foreach ([
            [404, fn () => $this->exporter()->render('t1', 'e1', 'inconnu', self::RECORDS)],
            [422, fn () => $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS, 'pdf')],
            [422, function (): void {
                $this->settings->values['higher.minesup.export.bts.columns'] = ['Téléphone' => 'phone'];
                $this->exporter()->render('t1', 'e1', 'minesup.bts', self::RECORDS);
            }],
        ] as [$status, $call]) {
            try {
                $call();
                self::fail('Erreur ' . $status . ' attendue.');
            } catch (HttpException $e) {
                self::assertSame($status, $e->status);
            }
        }
    }
}
