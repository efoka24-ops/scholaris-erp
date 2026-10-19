<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Migration;

use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentImporter;
use Scholaris\Modules\Apps\Higher\Migration\Console\PilotSetupCommand;
use Tests\Support\Higher\Migration\MigrationTestCase;

/** higher:pilot-setup : squelette idempotent, simulation sans écriture, aucune autorisation inventée (filières non inscriptibles). */
final class PilotSetupTest extends MigrationTestCase
{
    protected function setUp(): void
    {
        $this->bootMigration();
    }

    private function install(array $args): string
    {
        $command = $this->kernel->container()->get('console.command.' . PilotSetupCommand::NAME);
        self::assertInstanceOf(PilotSetupCommand::class, $command);
        ob_start();
        $code = $command->run($args);
        $output = (string) ob_get_clean();
        self::assertSame(0, $code, $output);

        return $output;
    }

    public function testSimulationNEcritRien(): void
    {
        $programs = $this->rows('hi_programs');
        $output = $this->install([self::T, 'sante', '--dry-run', '--year=' . self::YEAR]);

        self::assertStringContainsString('SIMULATION', $output);
        self::assertStringContainsString('serait créé', $output);
        self::assertSame($programs, $this->rows('hi_programs'));
        self::assertSame(0, $this->rows('hi_fee_grids'));
    }

    public function testInstalleLeSqueletteSansAutorisationEtEstIdempotente(): void
    {
        $authorizations = $this->rows('hi_authorizations');
        $this->install([self::T, 'complet', '--year=' . self::YEAR]);

        self::assertSame(11, $this->rows("hi_programs WHERE name LIKE '%[à confirmer]'"));
        self::assertSame(11, $this->rows("hi_programs WHERE status = 'closed'"));
        self::assertSame(2, $this->rows("hi_schools WHERE code IN ('SANTE', 'GESTION')"));
        self::assertSame($authorizations, $this->rows('hi_authorizations'), 'Aucune autorisation inventée.');
        self::assertSame(2, $this->rows("hi_fee_grids WHERE status = 'draft' AND fees_json = '[]'"));
        self::assertSame(2, $this->rows("hi_document_school_profiles WHERE school_code IN ('SANTE', 'GESTION')"));

        $levels = $this->rows('hi_levels');
        $second = $this->install([self::T, 'complet', '--year=' . self::YEAR]);
        self::assertStringContainsString('existe déjà', $second);
        self::assertSame(11, $this->rows("hi_programs WHERE name LIKE '%[à confirmer]'"));
        self::assertSame($levels, $this->rows('hi_levels'));
        self::assertSame(2, $this->rows('hi_fee_grids'));

        $level = $this->service(\Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory::class);
        $program = $this->service(\Scholaris\Modules\Apps\Higher\Academic\Contracts\StructureLookup::class)->programsByCode(self::T, self::E, 'EMS-SF')[0];
        $first = $level->levelsOf(self::T, self::E, $program->id)[0];
        self::assertNotNull($this->service(EnrolmentImporter::class)->refusal(self::T, self::E, $first->id, null, self::YEAR), 'Filière non inscriptible sans autorisation réelle.');
    }

    public function testModeleOuClientInconnus(): void
    {
        $command = $this->kernel->container()->get('console.command.' . PilotSetupCommand::NAME);
        ob_start();
        $code = $command->run([self::T, 'inconnu']);
        ob_end_clean();
        self::assertSame(1, $code);
        ob_start();
        $code = $command->run(['client-sans-ipes', 'sante']);
        ob_end_clean();
        self::assertSame(1, $code);
    }
}
