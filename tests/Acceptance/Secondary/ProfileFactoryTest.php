<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Tests\Support\Secondary\ProfileFactory;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T950 : la fabrique de test produit un établissement OUVERT de chacun des 7 types, public et privé quand le profil le permet,
 * chacun avec les modules par défaut de son type (valeurs de départ « à confirmer »). Base des tests par type TST-T1 à TST-T7.
 */
final class ProfileFactoryTest extends SecondaryTestCase
{
    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    public function testSeptTypesPublicsEtPrivesAvecLeursModulesParDefaut(): void
    {
        $variants = $this->factory->variants(self::TA);
        $resolver = $this->service(ProfileResolver::class);

        $types = [];
        $ownerships = [];
        foreach ($variants as $key => $establishmentId) {
            [$code, $ownership] = explode(':', $key);
            $expected = ProfileFactory::EXPECTED[$code];
            $profile = $resolver->forEstablishment(self::TA, $establishmentId);
            self::assertTrue($profile->stored, $key . ' : profil copié à l\'ouverture');
            self::assertSame($ownership, $profile->ownership, $key);
            self::assertSame($expected['modules'], $profile->modules, $key);
            self::assertSame($expected['cycles'], $profile->cycles, $key);
            if ($expected['ownership'] !== null) {
                self::assertSame($expected['ownership'], $ownership, $key . ' : propriété fixée par le type');
            }
            $types[$code] = true;
            $ownerships[$ownership === 'public' ? 'public' : 'private'] = true;
        }

        self::assertSame(ProfileFactory::CODES, array_keys($types));
        self::assertSame(['public' => true, 'private' => true], ['public' => $ownerships['public'] ?? false, 'private' => $ownerships['private'] ?? false]);
        self::assertArrayHasKey('college_prive:private_secular', $variants);
        self::assertArrayHasKey('lycee_prive:private_faith', $variants);
        self::assertCount(count(array_unique($variants)), $variants, 'Un établissement par variante.');
    }
}
