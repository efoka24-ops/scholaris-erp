<?php

declare(strict_types=1);

namespace Tests\Isolation\Higher\Academic;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Higher\Academic\CurriculumTestCase;

/**
 * Isolation d'Academic et de Curriculum (TST-03, TST-12) : autre client (404, listes vides, contrats bornés), autre école
 * (404 en lecture, 403 journalisé en écriture), autre département, enseignant non affecté.
 */
final class AcademicIsolationTest extends CurriculumTestCase
{
    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    private array $other;

    private string $curriculum = '';
    private string $authorization = '';
    private string $regulation = '';

    protected function setUp(): void
    {
        $this->bootCurriculum();
        $this->curriculum = $this->marie()['id'];
        $this->authorization = (string) $this->call('GET', '/higher/authorizations')[1]['data'][0]['id'];
        $this->regulation = (string) $this->ok('POST', '/higher/regulations', ['school_id' => $this->org['school'], 'regime' => 'lmd', 'academic_year_id' => self::YEAR])['id'];
        $this->other = $this->buildOrg('bts', 'INFO', 'IT');
    }

    /** @return list<string> chemins de lecture d'objets du client A, école EC */
    private function paths(): array
    {
        return [
            '/higher/schools/' . $this->org['school'], '/higher/programs/' . $this->org['program'], '/higher/curricula/' . $this->curriculum,
            '/higher/curricula/' . $this->curriculum . '/export', '/higher/authorizations/' . $this->authorization, '/higher/regulations/' . $this->regulation,
        ];
    }

    public function testUnAutreClientNeVoitRien(): void
    {
        foreach ($this->paths() as $path) {
            self::assertSame(404, $this->call('GET', $path, null, self::ADMIN_B, self::TB, self::EB)[0], $path);
        }
        foreach (['/higher/schools', '/higher/programs', '/higher/curricula', '/higher/authorizations', '/higher/tutelages', '/higher/regulations'] as $list) {
            self::assertSame([], $this->call('GET', $list, null, self::ADMIN_B, self::TB, self::EB)[1]['data'], $list);
        }
        self::assertSame(404, $this->call('PATCH', '/higher/programs/' . $this->org['program'], ['name' => 'X'], self::ADMIN_B, self::TB, self::EB)[0]);
        self::assertSame(404, $this->call('POST', '/higher/curricula/' . $this->curriculum . '/publish', null, self::ADMIN_B, self::TB, self::EB)[0]);
        self::assertSame(422, $this->call('POST', '/higher/departments', ['school_id' => $this->org['school'], 'code' => 'X', 'name' => 'X'], self::ADMIN_B, self::TB, self::EB)[0]);
        self::assertSame(403, $this->call('GET', '/higher/programs', null, self::ADMIN, self::T, self::EB)[0], 'Établissement d\'un autre client.');

        self::assertNull($this->service(AcademicDirectory::class)->program(self::TB, self::EB, $this->org['program']));
        self::assertNull($this->service(CurriculumDirectory::class)->find(self::TB, self::EB, $this->curriculum));
    }

    public function testUnDirecteurDUneAutreEcoleEtUnChefDUnAutreDepartement(): void
    {
        foreach (['higher.settings.read', 'higher.settings.manage', 'higher.curriculum.read', 'higher.curriculum.manage'] as $permission) {
            $this->assign(self::T, 'dir-it', self::E, $permission, Scope::ORG_UNIT, $this->other['school'], 'higher_dir_ecole');
        }
        $this->assign(self::T, 'chef-info', self::E, 'higher.curriculum.manage', Scope::ORG_UNIT, $this->other['department'], 'higher_chef_dept');
        $this->assign(self::T, 'chef-info', self::E, 'higher.curriculum.read', Scope::ORG_UNIT, $this->other['department'], 'higher_chef_dept');

        foreach ($this->paths() as $path) {
            self::assertSame(404, $this->call('GET', $path, null, 'dir-it')[0], $path);
        }
        self::assertSame(403, $this->call('PATCH', '/higher/curricula/' . $this->curriculum, ['title' => 'X'], 'dir-it')[0]);
        self::assertSame(403, $this->call('PATCH', '/higher/curricula/' . $this->curriculum, ['title' => 'X'], 'chef-info')[0]);
        self::assertSame(403, $this->call('POST', '/higher/levels', ['program_id' => $this->org['program'], 'ordinal' => 3, 'code' => 'L3', 'name' => 'L3'], 'dir-it')[0]);
        self::assertSame(403, $this->call('PATCH', '/higher/regulations/' . $this->regulation, ['parameters' => []], 'dir-it')[0]);
        self::assertSame([], $this->call('GET', '/higher/curricula', null, 'chef-info')[1]['data']);
        self::assertSame([$this->other['program']], array_column($this->call('GET', '/higher/programs', null, 'dir-it')[1]['data'], 'id'));
        self::assertGreaterThanOrEqual(4, count(array_filter($this->audit->entries, static fn (array $e): bool => $e['action'] === 'higher.scope.denied')));
    }

    public function testUnEnseignantNonAffecteNeVoitPasLaMaquette(): void
    {
        $this->assign(self::T, 'prof-info', self::E, 'higher.curriculum.read', Scope::CLASS_GROUP, $this->other['g1'], 'enseignant');
        $this->assign(self::T, 'prof-cf', self::E, 'higher.curriculum.read', Scope::STRUCTURE, $this->org['program'], 'enseignant');

        self::assertSame(404, $this->call('GET', '/higher/curricula/' . $this->curriculum, null, 'prof-info')[0]);
        self::assertSame([], $this->call('GET', '/higher/curricula', null, 'prof-info')[1]['data']);
        self::assertSame(200, $this->call('GET', '/higher/curricula/' . $this->curriculum, null, 'prof-cf')[0], 'Affecté à la filière : lecture.');
        self::assertSame(403, $this->call('PATCH', '/higher/curricula/' . $this->curriculum, ['title' => 'X'], 'prof-cf')[0], 'Lecture seule.');
    }
}
