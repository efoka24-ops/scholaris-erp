<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Internships;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipClock;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * Base des tests des stages : organisation RÉELLE (école EC > CF ; seconde école EG > GE), doublures des inscriptions, comptes,
 * personnes et documents ; horloge figée au 2026-10-15.
 *
 * Acteurs (client A) : responsable des stages de l'école EC (R16), directeur de l'école EC (signature, lecture), responsable des
 * stages de l'école EG (hors périmètre), étudiants Marie (p1) et Paul (p2) de CF, président de jury (personne j1).
 * Jury type : j1 président, sup1 encadreur académique, j2 membre.
 */
abstract class InternshipsTestCase extends AcademicTestCase
{
    public const OFFICER = 'resp-stages';
    public const OTHER_OFFICER = 'resp-stages-eg';
    public const DIRECTOR = 'dir-ecole';
    public const STUDENT = 'student-marie';
    public const OTHER_STUDENT = 'student-paul';
    public const PRESIDENT = 'jury-president';
    public const SUPERVISOR_USER = 'encadreur';

    protected InternshipFakes $fakes;
    protected InternshipClock $clock;

    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    protected array $org;

    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    protected array $otherOrg;

    protected function bootInternships(): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg();
        $this->otherOrg = $this->buildOrg('lmd', 'GE', 'EG');
        $this->fakes = new InternshipFakes();
        $this->clock = new InternshipClock('2026-10-15');
        $container = $this->kernel->container();
        foreach ([StudentDirectory::class, UserPersonLink::class, PersonDirectory::class, HigherDocumentIssuer::class, IssuedDocuments::class] as $contract) {
            $container->set($contract, $this->fakes);
        }
        $container->set(InternshipClock::class, $this->clock);

        $this->fakes->enrol('p1', $this->org['school'], $this->org['program'], $this->org['l2'], null, self::YEAR);
        $this->fakes->enrol('p2', $this->org['school'], $this->org['program'], $this->org['l2'], null, self::YEAR);
        $this->fakes->names = ['p1' => 'Marie Ngo', 'p2' => 'Paul Eto', 'j1' => 'Pr Abena', 'sup1' => 'Dr Kamga', 'j2' => 'M. Fouda', 'j3' => 'Mme Biya'];
        $this->fakes->link(self::T, self::STUDENT, 'p1');
        $this->fakes->link(self::T, self::OTHER_STUDENT, 'p2');
        $this->fakes->link(self::T, self::PRESIDENT, 'j1');
        $this->fakes->link(self::T, self::SUPERVISOR_USER, 'sup1');

        foreach (['higher.internship.manage', 'higher.defense.manage'] as $permission) {
            $this->assign(self::T, self::OFFICER, self::E, $permission, 'org_unit', $this->org['school'], 'higher_resp_stages');
            $this->assign(self::T, self::OTHER_OFFICER, self::E, $permission, 'org_unit', $this->otherOrg['school'], 'higher_resp_stages');
        }
        foreach (['higher.internship.read', 'higher.document.sign_school'] as $permission) {
            $this->assign(self::T, self::DIRECTOR, self::E, $permission, 'org_unit', $this->org['school'], 'higher_dir_ecole');
        }
        foreach ([self::STUDENT, self::OTHER_STUDENT, self::PRESIDENT, self::SUPERVISOR_USER] as $user) {
            $this->assign(self::T, $user, self::E, 'self.read', 'establishment', null, 'apprenant');
        }
    }

    protected function tearDown(): void
    {
        unset($this->fakes, $this->clock);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function company(string $user = self::OFFICER): array
    {
        return $this->ok('POST', '/higher/internship-companies', ['name' => 'Brasseries du Cameroun', 'city' => 'Douala', 'sector' => 'Agroalimentaire'], $user);
    }

    /**
     * Convention en brouillon pour la personne donnée.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function convention(string $person = 'p1', array $extra = []): array
    {
        $company = $this->company();

        return $this->ok('POST', '/higher/internships', $extra + [
            'enrolment_id' => 'enr-' . $person, 'company_id' => $company['id'], 'subject' => 'Contrôle de gestion d\'une PME',
            'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31', 'company_tutor_name' => 'M. Tchana', 'supervisor_person_id' => 'sup1',
        ], self::OFFICER);
    }

    /**
     * Convention émise puis signée par les trois parties.
     *
     * @return array<string, mixed>
     */
    protected function signed(string $person = 'p1'): array
    {
        $id = (string) $this->convention($person)['id'];
        $this->ok('POST', '/higher/internships/' . $id . '/convention', null, self::OFFICER);
        $this->ok('POST', '/higher/internships/' . $id . '/signatures', ['party' => 'student', 'signed_on' => '2026-05-20'], self::OFFICER);
        $this->ok('POST', '/higher/internships/' . $id . '/signatures', ['party' => 'company', 'signed_on' => '2026-05-21'], self::OFFICER);

        return $this->ok('POST', '/higher/internships/' . $id . '/signatures', ['party' => 'school', 'signed_on' => '2026-05-22'], self::DIRECTOR);
    }

    /**
     * Les quatre conditions de RG-STG-1 remplies.
     *
     * @return string identifiant de la convention
     */
    protected function ready(string $person = 'p1'): string
    {
        $id = (string) $this->signed($person)['id'];
        $this->ok('POST', '/higher/internships/' . $id . '/completion', ['received_on' => '2026-09-02', 'reference' => 'ATT-2026-15'], self::OFFICER);
        $this->ok('POST', '/higher/internships/' . $id . '/thesis', ['title' => 'Tableaux de bord d\'une PME', 'file_ref' => 'memoires/p1.pdf'], self::OFFICER);
        $this->fakes->quitus[$person] = true;

        return $id;
    }

    /** @return list<array{person_id: string, jury_role: string}> */
    protected static function jury(): array
    {
        return [
            ['person_id' => 'j1', 'jury_role' => 'president'],
            ['person_id' => 'sup1', 'jury_role' => 'supervisor'],
            ['person_id' => 'j2', 'jury_role' => 'member'],
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function schedule(string $internshipId, array $over = []): array
    {
        return $this->call('POST', '/higher/defenses', $over + [
            'internship_id' => $internshipId, 'held_on' => '2026-10-20', 'start_time' => '09:00', 'end_time' => '10:00', 'room' => 'Amphi A', 'jury' => self::jury(),
        ], self::OFFICER);
    }
}
