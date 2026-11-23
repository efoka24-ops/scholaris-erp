<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\CarteScolaire;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\ExamFeeStatus;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsWriter;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Secondary\CarteScolaire\FakeEnrollments;
use Tests\Support\Secondary\CarteScolaire\FakeSchool;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T828, T829 (EX-SEC-20, EX-SEC-21, EX-SEC-23, RG-CSC-1, RG-CSC-2, RG-INS-1) : matricule unique (format paramétrable), import CSV des
 * matricules avec rapprochement nom + date de naissance et rapport d'écarts, fichier rejoué idempotent, liste à régulariser des
 * niveaux d'entrée, import du relevé des paiements (inconnu, doublon), statut des frais d'examen. Données et matricules FICTIFS.
 */
final class CarteScolaireTest extends SecondaryTestCase
{
    private const REGISTRAR = 'scolarite-a';
    private const BURSAR = 'intendant-a';

    private FakeSchool $school;
    private string $leg;

    protected function setUp(): void
    {
        $this->school = new FakeSchool();
        $this->bootSecondary([
            PersonDirectory::class => $this->school,
            PersonDemographics::class => $this->school,
            EnrollmentDirectory::class => new FakeEnrollments($this->school),
            OrgUnitTree::class => $this->school,
        ]);
        $this->leg = $this->factory->opened(self::TA, 'leg');
        $this->school->level($this->leg, 'classe-6', 'niveau-6', '6e');
        $this->school->level($this->leg, 'classe-2', 'niveau-2', 'Seconde');
        $this->school->student(self::TA, $this->leg, 'p-aicha', 'Bello', 'Aïcha Marie', '2014-03-02', 'classe-6');
        $this->school->student(self::TA, $this->leg, 'p-brice', 'Nkou', 'Brice', '2013-07-19', 'classe-6');
        $this->school->student(self::TA, $this->leg, 'p-carine', 'Ewane', 'Carine', '2010-01-05', 'classe-2');
        $this->school->student(self::TA, $this->leg, 'p-dan', 'Fouda', 'Dan', '2010-11-11', 'classe-2');
        $this->grant(self::TA, self::REGISTRAR, $this->leg, ['secondary.enrol.manage']);
        $this->grant(self::TA, self::BURSAR, $this->leg, ['secondary.billing.manage', 'secondary.fees.read']);
    }

    private function rowsIn(string $table): int
    {
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM ' . $table);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function importIds(string $csv): array
    {
        return $this->ok('POST', '/secondary/carte-scolaire/national-ids/import', ['file_name' => 'matricules.csv', 'content' => $csv], self::REGISTRAR, self::TA, $this->leg);
    }

    /** @return array<string, string> personne => statut */
    private function statuses(string $query = ''): array
    {
        $out = [];
        foreach ($this->ok('GET', '/secondary/carte-scolaire/students' . $query, null, self::REGISTRAR, self::TA, $this->leg) as $row) {
            self::assertIsArray($row);
            $out[(string) $row['person_id']] = (string) $row['national_id_status'];
        }
        ksort($out);

        return $out;
    }

    public function testSaisieManuelleAvecFormatParametreEtUnicite(): void
    {
        [$bad] = $this->call('PUT', '/secondary/carte-scolaire/students/p-aicha/national-id', ['national_id' => 'x!'], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(422, $bad);
        $ok = $this->ok('PUT', '/secondary/carte-scolaire/students/p-aicha/national-id', ['national_id' => ' fict 000123 '], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame('FICT000123', $ok['national_id']);
        [$taken] = $this->call('PUT', '/secondary/carte-scolaire/students/p-brice/national-id', ['national_id' => 'FICT000123'], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(409, $taken);
        [$unknown] = $this->call('PUT', '/secondary/carte-scolaire/students/inconnu/national-id', ['national_id' => 'FICT000999'], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(404, $unknown);
        [$denied] = $this->call('PUT', '/secondary/carte-scolaire/students/p-brice/national-id', ['national_id' => 'FICT000124'], self::BURSAR, self::TA, $this->leg);
        self::assertSame(403, $denied);

        $this->service(SettingsWriter::class)->presetIfAbsent(self::TA, $this->leg, 'secondary.carte_scolaire.national_id_pattern', 'MAT[0-9]{6}', 'test');
        [$format] = $this->call('PUT', '/secondary/carte-scolaire/students/p-brice/national-id', ['national_id' => 'FICT000124'], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(422, $format, 'Format paramétré par l\'établissement.');
        self::assertSame('MAT123456', $this->ok('PUT', '/secondary/carte-scolaire/students/p-brice/national-id', ['national_id' => 'mat123456'], self::REGISTRAR, self::TA, $this->leg)['national_id']);
        self::assertSame('p-brice', $this->service(NationalIds::class)->personIdByNationalId(self::TA, $this->leg, 'MAT123456'));
        self::assertContains('secondary.national_id.recorded', $this->audit->actions());
    }

    public function testImportDesMatriculesRapprochementEtRapportDEcarts(): void
    {
        $csv = "\xEF\xBB\xBFmatricule;nom;prenoms;date_naissance;classe\r\n"
            . "FICT000001;BELLO;Aicha Marie;02/03/2014;6e A\r\n"     // accents et format de date différents : rapproché
            . "FICT000002;Brice;NKOU;2013-07-19;6e A\r\n"            // nom et prénom inversés : rapproché
            . "FICT000003;Ewane;Carine;2010-02-05;2nde A\r\n"        // date différente : écart d'identité
            . "FICT000004;Tchoupo;Gaelle;2011-04-04;2nde B\r\n"      // inconnue de l'application
            . "??;Fouda;Dan;2010-11-11;2nde A\r\n"                   // format rejeté
            . "FICT000001;Bello;Aicha;2014-03-02;6e A\r\n";          // matricule répété

        $import = $this->importIds($csv);
        self::assertSame([6, 2, 2], [$import['rows_total'], $import['rows_ok'], $import['rows_rejected']]);
        self::assertFalse($import['replayed']);

        self::assertSame(['p-aicha' => 'recorded', 'p-brice' => 'recorded', 'p-carine' => 'mismatch', 'p-dan' => 'pending'], $this->statuses());
        $detail = $this->ok('GET', '/secondary/carte-scolaire/imports/' . $import['id'], null, self::REGISTRAR, self::TA, $this->leg);
        $kinds = array_count_values(array_column($detail['discrepancies'], 'discrepancy_kind'));
        ksort($kinds);
        self::assertSame(['identity' => 1, 'missing_local' => 1, 'missing_remote' => 1], $kinds);
        self::assertSame([6, 7], array_column($detail['rejected_rows'], 'line'), 'Numéros de ligne du fichier (en-tête = 1).');

        $replayed = $this->ok('POST', '/secondary/carte-scolaire/national-ids/import', ['file_name' => 'copie.csv', 'content' => $csv], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame($import['id'], $replayed['id']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(1, $this->rowsIn('se_cs_imports'));
        self::assertSame(3, $this->rowsIn('se_cs_discrepancies'), 'Rien n\'est réécrit.');

        $identity = array_values(array_filter($detail['discrepancies'], static fn (array $d): bool => $d['discrepancy_kind'] === 'identity'))[0];
        $resolved = $this->ok('POST', '/secondary/carte-scolaire/discrepancies/' . $identity['id'] . '/resolve', ['note' => 'Acte de naissance vérifié', 'apply_national_id' => true], self::REGISTRAR, self::TA, $this->leg);
        self::assertTrue($resolved['resolved']);
        self::assertSame('FICT000003', $this->service(NationalIds::class)->of(self::TA, $this->leg, 'p-carine'));
        $open = $this->ok('GET', '/secondary/carte-scolaire/discrepancies?resolved=0', null, self::REGISTRAR, self::TA, $this->leg);
        self::assertCount(2, $open);
    }

    public function testListeARegulariserDesNiveauxDEntree(): void
    {
        $this->ok('PUT', '/secondary/carte-scolaire/students/p-aicha/national-id', ['national_id' => 'FICT000001'], self::REGISTRAR, self::TA, $this->leg);

        self::assertSame(['p-brice' => 'pending'], $this->statuses('?status=pending&entry_only=1'));
        self::assertSame(['p-brice' => 'pending', 'p-carine' => 'pending', 'p-dan' => 'pending'], $this->statuses('?status=pending'));
    }

    public function testImportDuReleveDesPaiementsInconnuDoublonEtFraisDExamen(): void
    {
        $this->ok('PUT', '/secondary/carte-scolaire/students/p-carine/national-id', ['national_id' => 'FICT000003'], self::REGISTRAR, self::TA, $this->leg);
        $csv = "reference;matricule;nature;montant;date;examen\n"
            . "REF-001;FICT000003;Frais d'examen;15 000;2026-10-01;BAC\n"
            . "REF-002;FICT000003;Contributions exigibles;7500;01/10/2026;\n"
            . "REF-003;FICT000099;Contribution;7500;2026-10-02;\n"
            . "REF-001;FICT000003;Examen;15000;2026-10-01;BAC\n"
            . "REF-004;FICT000003;Cotisation inconnue;1000;2026-10-02;\n";

        [$denied] = $this->call('POST', '/secondary/carte-scolaire/payments/import', ['file_name' => 'releve.csv', 'content' => $csv], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(403, $denied, 'Import du relevé : intendant (R11).');
        $import = $this->ok('POST', '/secondary/carte-scolaire/payments/import', ['file_name' => 'releve.csv', 'content' => $csv], self::BURSAR, self::TA, $this->leg);
        self::assertSame([5, 2, 1, 1, 1], [$import['rows_total'], $import['rows_ok'], $import['rows_unknown'], $import['rows_duplicate'], $import['rows_rejected']]);

        $unknown = $this->ok('GET', '/secondary/carte-scolaire/payments?filter[match_status]=unknown', null, self::BURSAR, self::TA, $this->leg);
        self::assertSame(['REF-003'], array_column($unknown, 'external_ref'));
        $fees = $this->service(ExamFeeStatus::class);
        self::assertTrue($fees->seen(self::TA, $this->leg, 'p-carine', 'BAC'));
        self::assertFalse($fees->seen(self::TA, $this->leg, 'p-carine', 'BEPC'));
        self::assertFalse($fees->seen(self::TA, $this->leg, 'p-dan'));

        $this->ok('PUT', '/secondary/carte-scolaire/students/p-dan/national-id', ['national_id' => 'FICT000099'], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame([], $this->ok('GET', '/secondary/carte-scolaire/payments?filter[match_status]=unknown', null, self::BURSAR, self::TA, $this->leg), 'Rattaché dès que le matricule est saisi.');

        $second = $this->ok('POST', '/secondary/carte-scolaire/payments/import', ['file_name' => 'releve2.csv', 'content' => "reference;matricule;nature;montant;date\nREF-002;FICT000003;Contribution;7500;2026-10-01\n"], self::BURSAR, self::TA, $this->leg);
        self::assertSame([1, 0], [$second['rows_duplicate'], $second['rows_ok']], 'Référence déjà importée : ignorée.');
    }

    public function testColonneAbsenteRefuseeAvantToutEcritEtIsolationDesClients(): void
    {
        [$status, $json] = $this->call('POST', '/secondary/carte-scolaire/national-ids/import', ['file_name' => 'x.csv', 'content' => "nom;prenoms\nBello;Aicha\n"], self::REGISTRAR, self::TA, $this->leg);
        self::assertSame(422, $status);
        self::assertArrayHasKey('content', $json['errors'] ?? []);
        self::assertSame(0, $this->rowsIn('se_cs_imports'));

        $import = $this->importIds("matricule;nom;prenoms;date_naissance\nFICT000001;Bello;Aicha Marie;2014-03-02\n");
        $other = $this->factory->opened(self::TB, 'leg');
        $this->grant(self::TB, 'scolarite-b', $other, ['secondary.enrol.manage']);
        [$foreign] = $this->call('GET', '/secondary/carte-scolaire/imports/' . $import['id'], null, 'scolarite-b', self::TB, $other);
        self::assertSame(404, $foreign);
        self::assertSame([], $this->ok('GET', '/secondary/carte-scolaire/discrepancies', null, 'scolarite-b', self::TB, $other));
    }
}
