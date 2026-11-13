<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Cloisonnement de l'application secondaire (plan §7) : autre client, autre établissement, autre classe. Le Store impose client
 * et établissement à chaque requête ; le périmètre par classe cache (404) ce qui est hors affectation ; le profil d'un autre
 * client est introuvable.
 */
final class SecondaryScopeTest extends SecondaryTestCase
{
    public function testUneClasseHorsPerimetreEstCachee(): void
    {
        $scope = new ScopedByClass();
        $perimeter = new Perimeter('client-a', 'lycee-a', false, ['classe-a']);
        $scope->assertReadable($perimeter, ['class_id' => 'classe-a']);
        try {
            $scope->assertReadable($perimeter, ['class_id' => 'classe-b']);
            self::fail('Classe hors périmètre visible.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
        self::assertSame(['class_id IN (?, ?)', ['un', 'deux']], $scope->sqlFilter(new Perimeter('client-a', 'lycee-a', false, ['un', 'deux'])));
        self::assertSame(['1 = 0', []], $scope->sqlFilter(new Perimeter('client-a', 'lycee-a', false)));
        self::assertSame(['1 = 1', []], $scope->sqlFilter(new Perimeter('client-a', 'lycee-a', true)));
    }

    public function testLeStoreBorneClientEtEtablissementEtRefuseLesTablesHorsApplication(): void
    {
        $this->bootSecondary();
        $a = $this->factory->opened(self::TA, 'leg');
        $b = $this->factory->opened(self::TB, 'leg', null, 'e-leg-b');
        $store = $this->service(Store::class);
        self::assertCount(1, $store->select('se_establishment_profiles', self::TA, $a));
        self::assertSame([], $store->select('se_establishment_profiles', self::TA, $b), 'Établissement de B avec le client A : rien.');
        self::assertSame([], $store->select('se_establishment_profiles', self::TB, $a));
        $this->expectException(\InvalidArgumentException::class);
        $store->select('establishments', self::TA, $a);
    }

    public function testLeProfilDUnAutreClientEstIntrouvable(): void
    {
        $this->bootSecondary();
        $a = $this->factory->opened(self::TA, 'lycee_technique');
        try {
            $this->service(ProfileResolver::class)->forEstablishment(self::TB, $a);
            self::fail('Profil d\'un autre client lisible.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testUneAffectationDeClasseNeDonnePasLeParametrageDeLEtablissement(): void
    {
        $this->bootSecondary();
        $school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, 'prof-principal', $school, ['secondary.catalogue.read', 'secondary.settings.manage'], Scope::CLASS_GROUP, 'classe-fictive');
        self::assertSame(200, $this->call('GET', '/secondary/catalogue/streams', null, 'prof-principal', self::TA, $school)[0]);
        $body = ['code' => 'XCL', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Fictive', 'source_ref' => 'Note fictive'];
        self::assertSame(403, $this->call('POST', '/secondary/catalogue/streams', $body, 'prof-principal', self::TA, $school)[0]);
        self::assertContains('secondary.scope.denied', $this->audit->actions(), 'Refus de périmètre journalisé.');
    }
}
