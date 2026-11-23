<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Migration;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Tests\Support\Higher\Admissions\EntryTestCase;

/**
 * Base des tests de la reprise de données : organisation et maquette RÉELLES (école EC, filière CF, niveaux L1 et L2, groupe G1, maquette
 * Marie N. publiée pour AY-2026), doublures des écritures du socle (élèves, inscriptions, personnel). Personnes et fichiers FICTIFS.
 *
 * Acteurs : `importer` (higher.migration.manage dans l'école EC), `outsider` (aucune permission), `importer-b` (autre client).
 */
abstract class MigrationTestCase extends EntryTestCase
{
    public const IMPORTER = 'importer';
    public const OUTSIDER = 'outsider';
    public const IMPORTER_B = 'importer-b';
    public const IMPORTER_NO_2FA = 'importer-no2fa';
    public const PERMISSION = 'higher.migration.manage';

    protected MigrationFakes $migrationFakes;

    protected function bootMigration(): void
    {
        $this->bootEntry();
        $this->assign(self::T, self::IMPORTER, self::E, self::PERMISSION, 'org_unit', $this->org['school'], 'higher_scolarite');
        $this->assign(self::T, self::IMPORTER_NO_2FA, self::E, self::PERMISSION, 'org_unit', $this->org['school'], 'higher_scolarite');
        $this->assign(self::TB, self::IMPORTER_B, self::EB, self::PERMISSION, 'establishment');
        $this->access->grant(self::OUTSIDER, self::E);
        $this->secondFactor->validate(self::T, self::IMPORTER);
        $this->secondFactor->validate(self::TB, self::IMPORTER_B);
    }

    protected function bootHigher(array $overrides = [], bool $migrate = false): void
    {
        $this->migrationFakes = new MigrationFakes($this->people);
        parent::bootHigher($overrides + [
            LearnerWriter::class => $this->migrationFakes,
            EnrollmentWriter::class => $this->migrationFakes,
            AssignmentWriter::class => $this->migrationFakes,
            StaffDirectory::class => $this->migrationFakes->directory,
        ], $migrate);
    }

    protected function tearDown(): void
    {
        unset($this->migrationFakes);
        parent::tearDown();
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    protected function csv(array $header, array $rows): string
    {
        $lines = [implode(';', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(';', $row);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Fichier d'étudiants : nombre de lignes fictives sur la filière CF, niveau L1, groupe G1.
     *
     * @return string CSV
     */
    protected function studentsCsv(int $count, int $offset = 0): string
    {
        $rows = [];
        for ($i = 1; $i <= $count; ++$i) {
            $n = $offset + $i;
            $rows[] = [sprintf('26EC-CF-%04d', $n), 'Fictif' . $n, 'Etudiant' . $n, '2005-03-14', 'Garoua', $n % 2 === 0 ? 'F' : 'M', '+2376000' . sprintf('%05d', $n), '', 'CF', 'L1', 'G1', 'jour', 'non', '', ''];
        }

        return $this->csv(['matricule', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'sexe', 'telephone', 'email', 'filiere', 'niveau', 'groupe', 'mode', 'redoublant', 'person_id', 'confirmer_different'], $rows);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function simulate(string $kind, string $csv, string $user = self::IMPORTER, array $extra = []): array
    {
        return $this->ok('POST', '/higher/migration/batches', ['kind' => $kind, 'academic_year_id' => self::YEAR, 'format' => 'csv', 'content' => $csv, 'dry_run' => true, 'file_name' => 'reprise.csv'] + $extra, $user);
    }

    /** @return array<string, mixed> */
    protected function apply(string $batchId, string $user = self::IMPORTER): array
    {
        return $this->ok('POST', '/higher/migration/batches/' . $batchId . '/apply', [], $user);
    }

    protected function rows(string $table): int
    {
        return (int) $this->service(ConnectionFactory::class)->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
