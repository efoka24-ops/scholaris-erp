<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Academic;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Higher\Academic\AcademicTestCase;

/** EX-ACA-01/02 : écoles, départements, filières, niveaux, groupes ; RG-ORG-7 (régime) ; RG-ORG-2/3 (périmètre) ; RG-ORG-5. */
final class StructureTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        $this->bootAcademic();
    }

    public function testLaHierarchieEstCreeeDansLeSocleEtEtendueParHigher(): void
    {
        $org = $this->buildOrg();

        $tree = $this->service(OrgUnitTree::class);
        self::assertSame([$org['school'], $org['department']], $tree->descendantsOf(self::T, self::E, $org['school']));
        self::assertSame($org['program'], $tree->trackOf(self::T, self::E, $org['g1']));
        self::assertSame($org['l1'], $tree->levelOf(self::T, self::E, $org['g1']));

        $program = $this->ok('GET', '/higher/programs/' . $org['program']);
        self::assertSame(['lmd', 'fr', 'day', 3, true], [$program['regime'], $program['teaching_language'], $program['study_mode'], $program['duration_years'], $program['requires_tutelage']]);

        $info = $this->service(AcademicDirectory::class)->program(self::T, self::E, $org['program']);
        self::assertSame('EC', $info?->schoolCode);
        $levels = $this->service(AcademicDirectory::class)->levelsOf(self::T, self::E, $org['program']);
        self::assertSame(['L1', 'L2'], array_map(static fn ($l): string => $l->code, $levels));
        self::assertCount(1, $this->service(AcademicDirectory::class)->groupsOf(self::T, self::E, $org['l1']));

        $list = $this->call('GET', '/higher/programs?filter[regime]=lmd')[1];
        self::assertCount(1, $list['data']);
        self::assertContains('higher.program.created', array_column($this->audit->entries, 'action'));
    }

    public function testLeRegimeDUneFiliereNeChangeJamais(): void
    {
        $org = $this->buildOrg();

        [$status, $problem] = $this->call('PATCH', '/higher/programs/' . $org['program'], ['regime' => 'bts']);
        self::assertSame(409, $status);
        self::assertStringContainsString('RG-ORG-7', (string) $problem['detail']);

        $same = $this->ok('PATCH', '/higher/programs/' . $org['program'], ['regime' => 'lmd', 'study_mode' => 'evening']);
        self::assertSame(['lmd', 'evening'], [$same['regime'], $same['study_mode']]);
    }

    public function testControlesDeSaisieEtDoublons(): void
    {
        $org = $this->buildOrg();

        self::assertSame(409, $this->call('POST', '/higher/schools', ['code' => 'ec', 'name' => 'Doublon'])[0]);
        self::assertSame(422, $this->call('POST', '/higher/levels', ['program_id' => $org['program'], 'ordinal' => 4, 'code' => 'L4', 'name' => 'Licence 4'])[0], 'Rang au-delà de la durée.');
        self::assertSame(409, $this->call('POST', '/higher/levels', ['program_id' => $org['program'], 'ordinal' => 1, 'code' => 'L1B', 'name' => 'Autre L1'])[0]);
        self::assertSame(422, $this->call('PATCH', '/higher/programs/' . $org['program'], ['duration_years' => 1])[0], 'Durée inférieure au dernier niveau.');
        [$status, $problem] = $this->call('POST', '/higher/programs', ['department_id' => $org['department'], 'code' => 'X', 'name' => 'X', 'regime' => 'doctorat', 'duration_years' => 3, 'inconnu' => 1]);
        self::assertSame(422, $status);
        self::assertArrayHasKey('regime', $problem['errors']);
        self::assertArrayHasKey('inconnu', $problem['errors']);
    }

    public function testLeDirecteurDEcoleNAgitQueDansSonEcole(): void
    {
        $ec = $this->buildOrg();
        $it = $this->buildOrg('bts', 'INFO', 'IT');
        $this->assign(self::T, 'dir-ec', self::E, 'higher.settings.manage', Scope::ORG_UNIT, $ec['school'], 'higher_dir_ecole');
        $this->assign(self::T, 'dir-ec', self::E, 'higher.settings.read', Scope::ORG_UNIT, $ec['school'], 'higher_dir_ecole');

        self::assertSame(201, $this->call('POST', '/higher/departments', ['school_id' => $ec['school'], 'code' => 'DR', 'name' => 'Droit'], 'dir-ec')[0]);
        self::assertSame(403, $this->call('POST', '/higher/departments', ['school_id' => $it['school'], 'code' => 'DR', 'name' => 'Droit'], 'dir-ec')[0]);
        self::assertSame(403, $this->call('POST', '/higher/schools', ['code' => 'NEW', 'name' => 'Nouvelle école'], 'dir-ec')[0], 'Créer une école exige tout l\'établissement.');
        self::assertSame(403, $this->call('PATCH', '/higher/programs/' . $it['program'], ['name' => 'Piratage'], 'dir-ec')[0]);
        self::assertContains('higher.scope.denied', array_column($this->audit->entries, 'action'));

        $programs = $this->call('GET', '/higher/programs', null, 'dir-ec')[1]['data'];
        self::assertSame([$ec['program']], array_column($programs, 'id'));
        self::assertSame(404, $this->call('GET', '/higher/programs/' . $it['program'], null, 'dir-ec')[0]);
        self::assertSame(404, $this->call('GET', '/higher/programs?school_id=' . $it['school'], null, 'dir-ec')[0], 'Sélecteur « École » hors périmètre.');
        self::assertCount(2, $this->call('GET', '/higher/programs?school_id=all')[1]['data']);
        self::assertCount(1, $this->call('GET', '/higher/programs?school_id=' . $it['school'])[1]['data']);
    }

    public function testSansPermissionRefusParDefaut(): void
    {
        $this->assign(self::T, 'curieux', self::E, 'higher.access', 'establishment');

        self::assertSame(403, $this->call('GET', '/higher/schools', null, 'curieux')[0]);
        self::assertSame(403, $this->call('POST', '/higher/schools', ['code' => 'Z', 'name' => 'Z'], 'curieux')[0]);
    }

    public function testApplicationSuspendueLectureSeule(): void
    {
        $org = $this->buildOrg();
        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);

        self::assertSame(200, $this->call('GET', '/higher/programs/' . $org['program'])[0]);
        self::assertSame(423, $this->call('POST', '/higher/schools', ['code' => 'NEW', 'name' => 'Nouvelle'])[0]);
        self::assertSame(423, $this->call('PATCH', '/higher/programs/' . $org['program'], ['name' => 'X'])[0]);
    }

    public function testParametresDeLEcoleEtSurchargeVisible(): void
    {
        $org = $this->buildOrg();
        $this->settings->set(self::E, 'higher.curriculum.credits_per_semester', 32, $org['school']);

        $items = $this->call('GET', '/higher/schools/' . $org['school'] . '/settings')[1]['data'];
        $credits = array_values(array_filter($items, static fn (array $i): bool => $i['key'] === 'higher.curriculum.credits_per_semester'))[0];
        self::assertSame([30, 32, true], [$credits['establishment_value'], $credits['school_value'], $credits['overridden']]);
    }

    public function testModificationsDesEcolesDepartementsNiveauxEtGroupes(): void
    {
        $org = $this->buildOrg();

        self::assertSame('Ecole renommée', $this->ok('PATCH', '/higher/schools/' . $org['school'], ['name' => 'Ecole renommée'])['name']);
        self::assertSame('FIN', $this->ok('PATCH', '/higher/departments/' . $org['department'], ['code' => 'fin'])['code']);
        self::assertSame('Première année', $this->ok('PATCH', '/higher/levels/' . $org['l1'], ['name' => 'Première année'])['name']);
        self::assertSame(80, $this->ok('PATCH', '/higher/groups/' . $org['g1'], ['capacity' => 80])['capacity']);
        self::assertSame('Première année', $this->service(OrgUnitTree::class)->structure(self::T, self::E, $org['l1'])?->name);
        self::assertCount(1, $this->call('GET', '/higher/departments?filter[school_id]=' . $org['school'])[1]['data']);
        self::assertCount(2, $this->call('GET', '/higher/levels?filter[program_id]=' . $org['program'])[1]['data']);
        self::assertCount(1, $this->call('GET', '/higher/groups?filter[level_id]=' . $org['l1'])[1]['data']);
        self::assertSame('EC', $this->ok('GET', '/higher/schools/' . $org['school'])['code']);
    }
}
