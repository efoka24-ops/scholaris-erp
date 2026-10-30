<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Academic;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * RG-ACA-2 (T214, T414) : pas d'inscription en filière LMD sans université de tutelle pour l'année ; RG-ORG-8 : rattachement
 * PAR ANNÉE, un changement de tutelle ne modifie pas les années passées.
 */
final class TutelageTest extends AcademicTestCase
{
    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    private array $org;

    protected function setUp(): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg('lmd');
        $this->authorize($this->org['program'], null);
    }

    private function refusal(string $year): ?string
    {
        return $this->service(EnrolmentGuards::class)->refusal(self::T, self::E, $this->org['g1'], $year, 'person-1', new DateTimeImmutable('2026-10-13 10:00:00', new DateTimeZone('Africa/Douala')));
    }

    public function testFiliereLmdSansTutelleDeLAnneeRefusee(): void
    {
        self::assertStringContainsString('RG-ACA-2', (string) $this->refusal(self::YEAR));

        $this->tutelage($this->org['program'], self::YEAR);

        self::assertNull($this->refusal(self::YEAR));
    }

    public function testLaTutelleDeLAnneePrecedenteNeSuffitPasEtNEstPasTouchee(): void
    {
        $previous = $this->tutelage($this->org['program'], self::PREVIOUS_YEAR, 'Université de Yaoundé II');

        self::assertStringContainsString('RG-ACA-2', (string) $this->refusal(self::YEAR), 'Tutelle N-1 seulement : année N refusée.');
        self::assertNull($this->refusal(self::PREVIOUS_YEAR));

        $this->tutelage($this->org['program'], self::YEAR, 'Université de Douala');

        self::assertNull($this->refusal(self::YEAR));
        $directory = $this->service(AcademicDirectory::class);
        self::assertSame('Université de Yaoundé II', $directory->tutelageFor(self::T, self::E, $this->org['program'], self::PREVIOUS_YEAR)?->universityName);
        self::assertSame('Université de Douala', $directory->tutelageFor(self::T, self::E, $this->org['program'], self::YEAR)?->universityName);
        self::assertSame($previous['updated_at'], $this->ok('GET', '/higher/tutelages/' . $previous['id'])['updated_at'], 'L\'année passée reste intacte.');
    }

    public function testUneSeuleTutelleParAnneeEtUniversiteFigee(): void
    {
        $tutelage = $this->tutelage($this->org['program']);

        self::assertSame(409, $this->call('POST', '/higher/tutelages', ['program_id' => $this->org['program'], 'academic_year_id' => self::YEAR, 'university_name' => 'Autre'])[0]);
        [$status, $problem] = $this->call('PATCH', '/higher/tutelages/' . $tutelage['id'], ['university_name' => 'Université de Buea']);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ORG-8', (string) $problem['detail']);

        $updated = $this->ok('PATCH', '/higher/tutelages/' . $tutelage['id'], ['convention_ends_on' => '2027-06-30', 'contacts' => [['name' => 'Mme Ebolo', 'role' => 'Scolarité']]]);
        self::assertSame(['2027-06-30', 'Mme Ebolo', 'UD/FSEG/LIC-CF/2024'], [$updated['convention_ends_on'], $updated['contacts'][0]['name'], $updated['approved_programme_ref']]);
        self::assertSame(422, $this->call('PATCH', '/higher/tutelages/' . $tutelage['id'], ['contacts' => [['role' => 'sans nom']]])[0]);
        self::assertCount(1, $this->call('GET', '/higher/tutelages?filter[program_id]=' . $this->org['program'])[1]['data']);
    }

    public function testSeuleUneFiliereLmdEstSousTutelleEtLesAutresRegimesNEnDependentPas(): void
    {
        $bts = $this->buildOrg('bts', 'CG', 'BT');
        $this->authorize($bts['program'], null);

        self::assertSame(422, $this->call('POST', '/higher/tutelages', ['program_id' => $bts['program'], 'academic_year_id' => self::YEAR, 'university_name' => 'X'])[0]);
        self::assertNull($this->service(EnrolmentGuards::class)->refusal(self::T, self::E, $bts['g1'], self::YEAR, 'p', new DateTimeImmutable('2026-10-13')));
    }

    public function testLeRegistreDesTutellesRelevesDuDirecteurAcademique(): void
    {
        $this->assign(self::T, 'dir-ecole', self::E, 'higher.settings.manage', Scope::ORG_UNIT, $this->org['school']);

        self::assertSame(403, $this->call('POST', '/higher/tutelages', ['program_id' => $this->org['program'], 'academic_year_id' => self::YEAR, 'university_name' => 'X'], 'dir-ecole')[0]);

        $this->assign(self::T, 'dir-acad', self::E, 'higher.tutelage.manage', 'establishment', null, 'higher_dir_academique');
        self::assertSame(201, $this->call('POST', '/higher/tutelages', ['program_id' => $this->org['program'], 'academic_year_id' => self::YEAR, 'university_name' => 'Université de Douala'], 'dir-acad')[0]);
    }
}
