<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Enrolment;

use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Tests\Fixtures\Higher\HigherOrgFixture as Org;
use Tests\Support\Attendance\InMemoryPeople;
use Tests\Support\Higher\HigherTestCase;

/**
 * Annuaire des noms de l'IPES (GET /higher/directory/names) : seules les personnes rattachées à l'établissement (étudiants inscrits,
 * enseignants, personnel, rôles IPES), seulement avec higher.access, jamais une personne d'un autre client ; 100 identifiants au plus.
 */
final class PersonNamesTest extends HigherTestCase
{
    private const A = Org::TENANT_A;
    private const EA = Org::ESTABLISHMENT_A;
    private const B = Org::TENANT_B;
    private const EB = Org::ESTABLISHMENT_B;

    protected function setUp(): void
    {
        $people = new InMemoryPeople();
        $people->add(self::A, 'marie', 'Marie', 'Nkoulou');
        $people->add(self::A, 'prof', 'Jean', 'Mbarga');
        $people->add(self::A, 'dir', 'Awa', 'Bello');
        $people->add(self::A, 'rh', 'Paul', 'Etoa');
        $people->add(self::A, 'outsider', 'Inconnu', 'Dehors');
        $people->add(self::B, 'bob', 'Bob', 'Tchana');

        $this->bootHigher([
            PersonDirectory::class => $people,
            StudentDirectory::class => self::students(['ipes-a|e-ipes-a|marie' => 'IPES-25-0001', 'ipes-b|e-ipes-b|bob' => 'B-25-0001']),
            StaffDirectory::class => self::staff(['ipes-a|e-ipes-a' => ['prof']], ['ipes-a|rh']),
            RoleDirectory::class => self::roles(['ipes-a|e-ipes-a' => ['dir']]),
        ]);
        $this->assign(self::A, 'scol', self::EA, 'higher.access', 'establishment');
        $this->assign(self::B, 'scolB', self::EB, 'higher.access', 'establishment');
        $this->access->grant('stranger', self::EA);
    }

    public function testRendLesNomsDesEtudiantsEtDuPersonnelRattaches(): void
    {
        [$response, $json] = $this->api('GET', '/higher/directory/names?ids=marie,prof,dir,rh,outsider,inconnu,marie', 'scol', self::A, self::EA);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $byId = array_column($json['data'], null, 'person_id');
        self::assertSame(['dir', 'marie', 'prof', 'rh'], self::sorted(array_keys($byId)));
        self::assertSame(['person_id' => 'marie', 'first_name' => 'Marie', 'last_name' => 'Nkoulou', 'matricule' => 'IPES-25-0001'], $byId['marie']);
        self::assertNull($byId['prof']['matricule']);
        self::assertSame('Etoa', $byId['rh']['last_name']);
        self::assertArrayNotHasKey('phone', $byId['marie'], 'Nom, prénom et matricule seulement');
    }

    public function testCloisonnementEntreClients(): void
    {
        [, $fromA] = $this->api('GET', '/higher/directory/names?ids=bob', 'scol', self::A, self::EA);
        self::assertSame([], $fromA['data'], 'Étudiant d\'un autre client');
        [, $fromB] = $this->api('GET', '/higher/directory/names?ids=marie,prof,dir,rh,bob', 'scolB', self::B, self::EB);
        self::assertSame(['bob'], array_column($fromB['data'], 'person_id'));
        [$foreign] = $this->api('GET', '/higher/directory/names?ids=marie', 'scolB', self::B, self::EA);
        self::assertContains($foreign->getStatusCode(), [403, 404], 'Établissement de A refusé au client B');
    }

    public function testSansHigherAccessRefusAvantValidation(): void
    {
        [$response, $json] = $this->api('GET', '/higher/directory/names', 'stranger', self::A, self::EA);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertArrayNotHasKey('errors', $json);
    }

    public function testValidationDeLaListe(): void
    {
        [$missing] = $this->api('GET', '/higher/directory/names', 'scol', self::A, self::EA);
        self::assertSame(422, $missing->getStatusCode());
        $ids = implode(',', array_map(static fn (int $i): string => 'p' . $i, range(1, 101)));
        [$tooMany, $json] = $this->api('GET', '/higher/directory/names?ids=' . $ids, 'scol', self::A, self::EA);
        self::assertSame(422, $tooMany->getStatusCode());
        self::assertArrayHasKey('ids', $json['errors'] ?? []);
        [$hundred] = $this->api('GET', '/higher/directory/names?ids=' . implode(',', array_map(static fn (int $i): string => 'p' . $i, range(1, 100))), 'scol', self::A, self::EA);
        self::assertSame(200, $hundred->getStatusCode());
        [$bad] = $this->api('GET', '/higher/directory/names?ids=' . rawurlencode("marie,x'--"), 'scol', self::A, self::EA);
        self::assertSame(422, $bad->getStatusCode());
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @param array<string, string> $matricules clé « client|établissement|personne » */
    private static function students(array $matricules): StudentDirectory
    {
        return new class ($matricules) implements StudentDirectory {
            /** @param array<string, string> $matricules */
            public function __construct(private readonly array $matricules)
            {
            }

            public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo
            {
                $m = $this->matricules[$tenantId . '|' . $establishmentId . '|' . $personId] ?? null;

                return $m === null ? null : new StudentInfo('st-' . $personId, $personId, $m, null, null, false);
            }

            public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo
            {
                return null;
            }

            public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array
            {
                return [];
            }

            public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
            {
                return null;
            }

            public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
            {
                return [];
            }

            public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
            {
                return false;
            }
        };
    }

    /**
     * @param array<string, list<string>> $teachers clé « client|établissement »
     * @param list<string> $members « client|personne »
     */
    private static function staff(array $teachers, array $members): StaffDirectory
    {
        return new class ($teachers, $members) implements StaffDirectory {
            /**
             * @param array<string, list<string>> $teachers
             * @param list<string> $members
             */
            public function __construct(private readonly array $teachers, private readonly array $members)
            {
            }

            public function find(string $tenantId, string $personId): ?StaffInfo
            {
                return in_array($tenantId . '|' . $personId, $this->members, true) ? new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, null, 'active') : null;
            }

            public function teacherIdsIn(string $tenantId, string $establishmentId): array
            {
                return $this->teachers[$tenantId . '|' . $establishmentId] ?? [];
            }
        };
    }

    /** @param array<string, list<string>> $holders clé « client|établissement » */
    private static function roles(array $holders): RoleDirectory
    {
        return new class ($holders) implements RoleDirectory {
            /** @param array<string, list<string>> $holders */
            public function __construct(private readonly array $holders)
            {
            }

            public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array
            {
                return in_array('higher_dir_ecole', $roles, true) && !in_array('apprenant', $roles, true) ? ($this->holders[$tenantId . '|' . $establishmentId] ?? []) : [];
            }
        };
    }
}
