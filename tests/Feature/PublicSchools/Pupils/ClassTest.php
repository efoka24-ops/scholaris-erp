<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/** CLA, EX-CLA-01..04 : classes jumelées, double flux, titulaire daté, effectifs par sexe et âge, alerte, répartition proposée. Données SYN-*. */
final class ClassTest extends PublicSchoolsTestCase
{
    private FakeStaffDirectory $staff;
    private string $unit = '';

    protected function setUp(): void
    {
        $this->staff = new FakeStaffDirectory();
        $this->staff->set('t1', 'e1', ['TEACHER-1', 'TEACHER-2']);
        $this->bootPublic(true, [StaffDirectory::class => $this->staff]);
        $this->seedSchool('t1', 'e1', 'SYN-E1');
        $this->seedSchool('t1', 'e3', 'SYN-E3');
        $this->seedSchool('t2', 'e9', 'SYN-E9');
        foreach ([['dir1', 't1', 'e1'], ['dir3', 't1', 'e3'], ['dir9', 't2', 'e9']] as [$user, $tenant, $establishment]) {
            $this->director($user, $tenant, $establishment, 'public_schools.pupils.read', 'public_schools.pupils.write');
        }
        $this->unit = $this->kernel->container()->get(StructureWriter::class)->createOrgUnit('t1', 'e1', 'school', 'École SYN e1', null, 'seed');
    }

    /** @return array<string, mixed> */
    private function create(array $changes = []): array
    {
        [$response, $json] = $this->api('POST', '/public/classes', 'dir1', 't1', 'e1', $changes + [
            'name' => 'CP SYN', 'academic_year_id' => 'y2026', 'org_unit_id' => $this->unit, 'level_codes' => ['SIL'], 'shift' => 'full_day',
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data'];
    }

    private function pupil(string $class, string $last, string $sex = 'F', string $birth = '2020-03-14'): string
    {
        [$response, $json] = $this->api('POST', '/public/pupils', 'dir1', 't1', 'e1', [
            'last_name' => $last, 'first_name' => 'Awa', 'birth_date' => $birth, 'sex' => $sex, 'birth_act_ref' => 'SYN-' . $last, 'class_id' => $class,
            'guardians' => [['kind' => 'parent', 'identity' => ['first_name' => 'Jean', 'last_name' => $last, 'phone' => '677000111']]],
        ]);
        self::assertSame(201, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));

        return $json['data']['person_id'];
    }

    public function testClasseJumeleeEtDoubleFlux(): void
    {
        $twinned = $this->create(['name' => 'CP-CE1 SYN', 'level_codes' => ['SIL', 'CE1']]);
        $morning = $this->create(['name' => 'CM1 matin SYN', 'level_codes' => ['CM1'], 'shift' => 'morning']);
        $afternoon = $this->create(['name' => 'CM1 soir SYN', 'level_codes' => ['CM1'], 'shift' => 'afternoon']);

        [, $json] = $this->api('GET', '/public/classes?academic_year_id=y2026', 'dir1', 't1', 'e1');
        $rows = array_column($json['data'], null, 'structure_id');
        self::assertTrue($rows[$twinned['structure_id']]['twinned']);
        self::assertSame(['SIL', 'CE1'], $rows[$twinned['structure_id']]['level_codes']);
        self::assertSame('morning', $rows[$morning['structure_id']]['shift']);
        self::assertSame('afternoon', $rows[$afternoon['structure_id']]['shift']);
        self::assertFalse($rows[$morning['structure_id']]['twinned']);
        self::assertSame(422, $this->code('POST', '/public/classes', 'dir1', 't1', 'e1', ['shift' => 'night', 'name' => 'X', 'academic_year_id' => 'y2026', 'org_unit_id' => $this->unit, 'level_codes' => ['SIL']]));
    }

    public function testModificationDeLaClasse(): void
    {
        $class = $this->create();

        [$updated, $json] = $this->api('PATCH', '/public/classes/' . $class['structure_id'], 'dir1', 't1', 'e1', ['name' => 'CP SYN renommée', 'level_codes' => ['SIL', 'CE1'], 'shift' => 'morning', 'capacity' => 45]);

        self::assertSame(200, $updated->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(['CP SYN renommée', ['SIL', 'CE1'], 'morning'], [$json['data']['label'], $json['data']['level_codes'], $json['data']['shift']]);
        self::assertSame(422, $this->code('PATCH', '/public/classes/' . $class['structure_id'], 'dir1', 't1', 'e1', ['homeroom_staff_id' => 'TEACHER-1']), 'Le titulaire a sa propre route datée.');
        self::assertSame(422, $this->code('PATCH', '/public/classes/' . $class['structure_id'], 'dir1', 't1', 'e1', ['capacity' => 0]));
    }

    public function testTitulaireDateEtHistorique(): void
    {
        $class = $this->create();
        $url = '/public/classes/' . $class['structure_id'] . '/homeroom';

        self::assertSame(200, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => 'TEACHER-1', 'starts_on' => '2026-09-01']));
        self::assertSame(422, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => 'TEACHER-2', 'starts_on' => '2026-09-01']), 'La date doit suivre celle du titulaire en place.');
        self::assertSame(422, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => 'INCONNU', 'starts_on' => '2026-10-01']));
        self::assertSame(422, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => 'TEACHER-2', 'starts_on' => '01/10/2026']));
        self::assertSame(200, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => 'TEACHER-2', 'starts_on' => '2026-10-01']));

        [, $history] = $this->api('GET', '/public/classes/' . $class['structure_id'] . '/homeroom-history', 'dir1', 't1', 'e1');
        self::assertSame([['TEACHER-1', '2026-09-01', '2026-09-30'], ['TEACHER-2', '2026-10-01', null]], array_map(static fn (array $row): array => [$row['staff_id'], $row['starts_on'], $row['ends_on']], $history['data']));
        [, $shown] = $this->api('GET', '/public/classes/' . $class['structure_id'], 'dir1', 't1', 'e1');
        self::assertSame('TEACHER-2', $shown['data']['homeroom_staff_id']);

        self::assertSame(200, $this->code('PUT', $url, 'dir1', 't1', 'e1', ['staff_id' => null, 'starts_on' => '2026-11-03']));
        [, $without] = $this->api('GET', '/public/classes/' . $class['structure_id'], 'dir1', 't1', 'e1');
        self::assertNull($without['data']['homeroom_staff_id'], 'RG-PRE-2 : classe sans enseignant.');
    }

    public function testEffectifsParSexeEtAgeEtAlerte(): void
    {
        $class = $this->create();
        $this->pupil($class['structure_id'], 'SYN-A', 'F', '2020-03-14');
        $this->pupil($class['structure_id'], 'SYN-B', 'M', '2020-06-01');
        $this->pupil($class['structure_id'], 'SYN-C', 'F', '2019-12-31');

        [, $json] = $this->api('GET', '/public/classes/' . $class['structure_id'] . '/headcount?date=2026-10-21', 'dir1', 't1', 'e1');
        self::assertSame(3, $json['data']['total']);
        self::assertSame(['female' => 2, 'male' => 1, 'unknown' => 0], $json['data']['by_sex']);
        self::assertSame([6 => 3], $json['data']['by_age']);
        self::assertSame([60, false], [$json['data']['threshold'], $json['data']['alert']]);

        $this->settings->values['public_schools.class_size.alert'] = 2;
        [, $alert] = $this->api('GET', '/public/classes/' . $class['structure_id'] . '/headcount', 'dir1', 't1', 'e1');
        self::assertTrue($alert['data']['alert']);
        [, $list] = $this->api('GET', '/public/classes?academic_year_id=y2026', 'dir1', 't1', 'e1');
        self::assertSame([3, true, 2], [$list['data'][0]['headcount'], $list['data'][0]['capacity_alert'], $list['data'][0]['alert_threshold']]);
        self::assertSame(422, $this->code('GET', '/public/classes/' . $class['structure_id'] . '/headcount?date=hier', 'dir1', 't1', 'e1'));
    }

    public function testRepartitionProposeeEquilibreeSansRienModifier(): void
    {
        $a = $this->create(['name' => 'CP A SYN']);
        $b = $this->create(['name' => 'CP B SYN']);
        foreach (['SYN-A' => ['F', '2020-01-10'], 'SYN-B' => ['F', '2020-02-10'], 'SYN-C' => ['M', '2020-03-10'], 'SYN-D' => ['M', '2020-04-10']] as $name => [$sex, $birth]) {
            $this->pupil($a['structure_id'], $name, $sex, $birth);
        }

        [$response, $json] = $this->api('GET', '/public/classes/distribution-proposal?class_ids=' . $a['structure_id'] . ',' . $b['structure_id'], 'dir1', 't1', 'e1');

        self::assertSame(200, $response->getStatusCode(), json_encode($json, JSON_THROW_ON_ERROR));
        $classes = array_column($json['data']['classes'], null, 'structure_id');
        self::assertSame([2, 2], [$classes[$a['structure_id']]['headcount'], $classes[$b['structure_id']]['headcount']]);
        self::assertSame([1, 1], [$classes[$a['structure_id']]['female'], $classes[$b['structure_id']]['female']]);
        self::assertSame([1, 1], [$classes[$a['structure_id']]['male'], $classes[$b['structure_id']]['male']]);
        self::assertCount(2, $json['data']['moves'], 'Deux élèves changent de classe.');
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM enrollments WHERE tenant_id = ? AND structure_id = ?', ['t1', $a['structure_id']]), 'La proposition ne modifie rien.');
        self::assertSame(422, $this->code('GET', '/public/classes/distribution-proposal?class_ids=' . $a['structure_id'], 'dir1', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/public/classes/distribution-proposal?class_ids=' . $a['structure_id'] . ',inconnue', 'dir1', 't1', 'e1'));
    }

    public function testPerimetreIsolationEtDroits(): void
    {
        $mine = $this->create(['name' => 'CP A SYN']);
        $other = $this->create(['name' => 'CP B SYN']);
        $this->teacher('maitre', 't1', 'e1', $mine['structure_id'], 'public_schools.pupils.read', 'public_schools.pupils.write');
        $this->supervisor('insp', 't1', 'e1', 'A1', 'pub_district_inspector', 'public_schools.pupils.read');

        [, $teacherView] = $this->api('GET', '/public/classes?academic_year_id=y2026', 'maitre', 't1', 'e1');
        self::assertSame([$mine['structure_id']], array_column($teacherView['data'], 'structure_id'));
        self::assertSame(404, $this->code('GET', '/public/classes/' . $other['structure_id'], 'maitre', 't1', 'e1'));
        self::assertSame(404, $this->code('GET', '/public/classes/' . $other['structure_id'] . '/headcount', 'maitre', 't1', 'e1'));
        self::assertSame(403, $this->code('POST', '/public/classes', 'maitre', 't1', 'e1', ['name' => 'X']));
        self::assertSame(403, $this->code('PATCH', '/public/classes/' . $mine['structure_id'], 'maitre', 't1', 'e1', ['name' => 'Y']));
        self::assertSame(403, $this->code('PUT', '/public/classes/' . $mine['structure_id'] . '/homeroom', 'maitre', 't1', 'e1', ['staff_id' => null, 'starts_on' => '2026-10-01']));
        self::assertSame(2, count($this->api('GET', '/public/classes?academic_year_id=y2026', 'insp', 't1', 'e1')[1]['data']), 'La supervision voit les classes de son territoire.');
        self::assertSame(403, $this->code('POST', '/public/classes', 'insp', 't1', 'e1', ['name' => 'X']));

        self::assertSame(404, $this->code('GET', '/public/classes/' . $mine['structure_id'], 'dir9', 't2', 'e9'), 'Autre client.');
        self::assertSame(404, $this->code('GET', '/public/classes/' . $mine['structure_id'], 'dir3', 't1', 'e3'), 'Autre école.');
        self::assertSame([], $this->api('GET', '/public/classes?academic_year_id=y2026', 'dir9', 't2', 'e9')[1]['data']);
        self::assertSame(404, $this->code('PATCH', '/public/classes/' . $mine['structure_id'], 'dir3', 't1', 'e3', ['name' => 'Y']));
    }
}
