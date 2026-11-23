<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Setup;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ModuleSwitch;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;
use Tests\Support\Secondary\ProfileFactory;
use Tests\Support\Secondary\SecondaryTestCase;

/** T813, EX-SEC-01 à 04, RG-SEC-1 et 2 : profil lu pour les 7 types, type inconnu refusé, copie à l'ouverture, modules activables. */
final class ProfileTest extends SecondaryTestCase
{
    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    public function testLeProfilDeTypeEstLuPourChacunDesSeptTypes(): void
    {
        $resolver = $this->service(ProfileResolver::class);
        self::assertSame(ProfileFactory::CODES, ProfileResolver::typeCodes());
        foreach ($this->factory->all(self::TA) as $code => $school) {
            $profile = $resolver->forEstablishment(self::TA, $school);
            $expected = ProfileFactory::EXPECTED[$code];
            self::assertFalse($profile->stored, $code);
            self::assertSame('to_confirm', $profile->confirmation, $code . ' : valeurs de départ à confirmer (Q18)');
            self::assertSame(1, $profile->profileVersion, $code);
            self::assertSame($expected['cycles'], $profile->cycles, $code);
            self::assertSame($expected['teaching_kinds'], $profile->teachingKinds, $code);
            self::assertSame($expected['ownership'], $profile->ownership, $code);
            self::assertSame($expected['modules'], $profile->modules, $code);
            self::assertSame($expected['head_title'], $profile->headTitle, $code);
            self::assertSame([], array_intersect($profile->modules, [Modules::BOARDING, Modules::CANTEEN_TRANSPORT, Modules::LIBRARY, Modules::INFIRMARY]), $code . ' : aucun service actif par défaut (Q28)');

            $this->grant(self::TA, self::ADMIN, $school, ['secondary.profile.read']);
            $data = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school);
            self::assertSame($code, $data['subtype']);
            self::assertSame($expected['cycles'], $data['cycles']);
            $states = array_column($data['modules'], 'state', 'code');
            self::assertCount(count(Modules::ALL), $states);
            foreach ($expected['modules'] as $module) {
                self::assertSame('active', $states[$module], $code . ' ' . $module);
            }
        }
    }

    public function testLeSecondCycleEstIndisponibleSansCycleDeuxEtLesAteliersRestentActivables(): void
    {
        $resolver = $this->service(ProfileResolver::class);
        $ces = $resolver->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'ces'));
        self::assertSame('unavailable', $ces->moduleState(Modules::SECOND_CYCLE));
        self::assertSame('unavailable', $ces->moduleState(Modules::ORIENTATION));
        self::assertSame('inactive', $ces->moduleState(Modules::WORKSHOPS));
        $leg = $resolver->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'leg'));
        self::assertSame('inactive', $leg->moduleState(Modules::WORKSHOPS), 'Un LEG peut activer les ateliers depuis l\'assistant (US1).');
    }

    public function testTypeAbsentInconnuOuHorsSecondaireEstRefuse(): void
    {
        $now = '2026-10-01T08:00:00+01:00';
        $insert = $this->pdo()->prepare('INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, establishment_subtype, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['e-sans-type', self::TA, 'Sans type', 'secondary', '{}', '[]', null, $now, $now]);
        $insert->execute(['e-type-inconnu', self::TA, 'Type inconnu', 'secondary', '{}', '[]', 'lycee_agricole', $now, $now]);
        $insert->execute(['e-primaire', self::TA, 'École primaire', 'primary', '{}', '[]', 'leg', $now, $now]);
        foreach (['e-sans-type', 'e-type-inconnu', 'e-primaire'] as $school) {
            $this->grant(self::TA, self::ADMIN, $school, ['secondary.profile.read']);
            [$status, $problem] = $this->call('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school);
            self::assertSame(422, $status, $school);
            self::assertSame(ProfileResolver::INVALID_TYPE, $problem['type'] ?? null, $school);
        }
        try {
            $this->service(ProfileResolver::class)->typeProfile(self::TA, 'e-sans-type', 'lycee_agricole');
            self::fail('Type hors liste fermée accepté.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function testLeProfilDeTypeEstUnParametreModifiableSansDeveloppement(): void
    {
        $school = $this->factory->establishment(self::TA, 'ces');
        $now = '2026-10-23T09:00:00+01:00';
        $this->pdo()->prepare('INSERT INTO settings (id, scope_type, tenant_id, establishment_id, setting_key, value_json, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['01SECTESTSET00000000000001', 'tenant', self::TA, '', 'secondary.type_profiles.ces', '{"version":2,"cycles":["first"],"teaching_kinds":["general"],"ownership":"public","modules":["official_exams","library","module_inconnu"],"sections":["fr","en"],"head_title":"principal"}', 'test', $now, $now]);
        $profile = $this->service(ProfileResolver::class)->forEstablishment(self::TA, $school);
        self::assertSame(2, $profile->profileVersion);
        self::assertSame(['official_exams', 'library'], $profile->modules, 'Un code de module inconnu est ignoré.');
        self::assertSame(['fr', 'en'], $profile->sections);
    }

    public function testLaCopieDOuvertureNeBougePlusQuandLaPlateformeChangeLeProfil(): void
    {
        $school = $this->factory->opened(self::TA, 'college_prive', 'private_faith');
        $this->pdo()->prepare('UPDATE settings SET value_json = ? WHERE setting_key = ?')->execute(['{"version":9,"cycles":["first","second"],"modules":[]}', 'secondary.type_profiles.college_prive']);
        $profile = $this->service(ProfileResolver::class)->forEstablishment(self::TA, $school);
        self::assertTrue($profile->stored);
        self::assertSame(1, $profile->profileVersion);
        self::assertSame(['first'], $profile->cycles);
        self::assertSame('private_faith', $profile->ownership);
        self::assertSame(ProfileFactory::EXPECTED['college_prive']['modules'], $profile->modules);
        $changes = $this->service(PdoProfileStore::class)->changes(self::TA, $school);
        self::assertSame(['opened'], array_column($changes, 'change_kind'));
    }

    public function testActiverPuisCouperUnModuleLeMetEnLectureSeuleAvecJournal(): void
    {
        $school = $this->factory->opened(self::TA, 'leg');
        $switch = $this->service(ModuleSwitch::class);
        $profile = $switch->enable(self::TA, $school, Modules::WORKSHOPS, 'Ouverture d\'un atelier de démonstration', self::ADMIN);
        self::assertTrue($profile->isActive(Modules::WORKSHOPS));
        $profile = $switch->disable(self::TA, $school, Modules::WORKSHOPS, 'Atelier fermé', self::ADMIN);
        self::assertFalse($profile->isActive(Modules::WORKSHOPS));
        self::assertTrue($profile->isReadOnly(Modules::WORKSHOPS));
        self::assertSame('read_only', $profile->moduleState(Modules::WORKSHOPS));
        $profile = $switch->enable(self::TA, $school, Modules::WORKSHOPS, 'Réouverture', self::ADMIN);
        self::assertSame('active', $profile->moduleState(Modules::WORKSHOPS));
        $changes = $this->service(PdoProfileStore::class)->changes(self::TA, $school);
        self::assertSame(['opened', 'modules', 'modules', 'modules'], array_column($changes, 'change_kind'));
        self::assertSame('Atelier fermé', $changes[2]['reason']);
    }

    public function testReglesDeLActivation(): void
    {
        $switch = $this->service(ModuleSwitch::class);
        $ces = $this->factory->opened(self::TA, 'ces');
        self::assertSame(422, self::statusOf(static fn () => $switch->enable(self::TA, $ces, Modules::SECOND_CYCLE, 'Essai', 'u')), 'Second cycle sans cycle deux (RG-ORG-1).');
        self::assertSame(422, self::statusOf(static fn () => $switch->enable(self::TA, $ces, 'module_inconnu', 'Essai', 'u')));
        self::assertSame(422, self::statusOf(static fn () => $switch->enable(self::TA, $ces, Modules::LIBRARY, '  ', 'u')), 'Motif obligatoire.');
        $notOpened = $this->factory->establishment(self::TA, 'leg');
        self::assertSame(409, self::statusOf(static fn () => $switch->enable(self::TA, $notOpened, Modules::LIBRARY, 'Essai', 'u')));
    }

    public function testDroitsEtIsolation(): void
    {
        $school = $this->factory->establishment(self::TA, 'leg');
        $this->access->grant('intrus', $school);
        self::assertSame(403, $this->call('GET', '/secondary/setup/profile', null, 'intrus', self::TA, $school)[0]);
        $other = $this->factory->establishment(self::TB, 'leg', 'e-leg-b');
        $this->grant(self::TB, 'admin-b', $other, ['secondary.profile.read']);
        self::assertContains($this->call('GET', '/secondary/setup/profile', null, 'admin-b', self::TB, $school)[0], [403, 404], 'Établissement d\'un autre client.');
        self::assertSame(200, $this->call('GET', '/secondary/setup/profile', null, 'admin-b', self::TB, $other)[0]);
    }

    private static function statusOf(\Closure $action): int
    {
        try {
            $action();
        } catch (HttpException $exception) {
            return $exception->status;
        }

        return 200;
    }
}
