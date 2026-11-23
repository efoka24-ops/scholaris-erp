<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\Messaging\Infrastructure\RoleBasedDirectionDirectory;
use Tests\Support\Identity\IdentityTestCase;

/** Annuaire des rôles publié par Identity, et direction d'établissement qui s'en déduit pour la messagerie. */
final class RoleDirectoryTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    private function directory(): RoleDirectory
    {
        return $this->kernel->container()->get(RoleDirectory::class);
    }

    public function testSeulsLesTitulairesDuRoleDansLEtablissementSontRetournes(): void
    {
        $dir1 = $this->createUser('t1', 'Dora', '+237691000031', [['directeur', 'establishment', 'e1']]);
        $this->createUser('t1', 'Denis', '+237691000032', [['directeur', 'establishment', 'e2']]);
        $this->createUser('t1', 'Tom', '+237691000033', [['enseignant', 'establishment', 'e1']]);
        $other = $this->createUser('t2', 'Dave', '+237691000034', [['directeur', 'establishment', 'e1']]);

        self::assertSame([$dir1['person_id']], $this->directory()->personIdsWithRole('t1', 'e1', ['directeur']));
        self::assertSame([$other['person_id']], $this->directory()->personIdsWithRole('t2', 'e1', ['directeur']), 'Isolation par client.');
        self::assertSame([], $this->directory()->personIdsWithRole('t1', 'e3', ['directeur']));
        self::assertSame([], $this->directory()->personIdsWithRole('t1', 'e1', []));
    }

    public function testUneAffectationExpireeOuFutureNeCompteesPas(): void
    {
        $moment = $this->kernel->container()->get(Moment::class);
        $past = $this->createUser('t1', 'Paul', '+237691000041');
        $future = $this->createUser('t1', 'Fred', '+237691000042');
        $current = $this->createUser('t1', 'Cora', '+237691000043');
        $this->assignRole('t1', $past['person_id'], 'directeur', 'establishment', 'e1', $moment->in(-10));
        $this->assignRole('t1', $future['person_id'], 'directeur', 'establishment', 'e1', null, $moment->in(3600));
        $this->assignRole('t1', $current['person_id'], 'directeur', 'establishment', 'e1');

        self::assertSame([$current['person_id']], $this->directory()->personIdsWithRole('t1', 'e1', ['directeur']));
    }

    public function testUnRoleDeNiveauClientSExerceDansTousLesEtablissements(): void
    {
        $group = $this->createUser('t1', 'Gaby', '+237691000051', [['directeur', 'tenant', null]]);

        self::assertSame([$group['person_id']], $this->directory()->personIdsWithRole('t1', 'e9', ['directeur']));
        self::assertSame([], $this->directory()->personIdsWithRole('t2', 'e9', ['directeur']));
    }

    public function testLaDirectionDUnEtablissementVientDesRolesDirecteurEtAdjoint(): void
    {
        $director = $this->createUser('t1', 'Dora', '+237691000061', [['directeur', 'establishment', 'e1']]);
        $deputy = $this->createUser('t1', 'Adèle', '+237691000062', [['directeur_adjoint', 'establishment', 'e1']]);
        $this->createUser('t1', 'Gina', '+237691000063', [['direction_groupe', 'tenant', null]]);
        $this->createUser('t1', 'Tom', '+237691000064', [['enseignant', 'establishment', 'e1']]);

        $direction = $this->kernel->container()->get(DirectionDirectory::class);
        $ids = $direction->directionPersonIds('t1', 'e1');
        sort($ids);
        $expected = [$director['person_id'], $deputy['person_id']];
        sort($expected);

        self::assertInstanceOf(RoleBasedDirectionDirectory::class, $direction);
        self::assertSame($expected, $ids);
        self::assertSame([], $direction->directionPersonIds('t1', 'e2'));
        self::assertSame([], $direction->directionPersonIds('t2', 'e1'));
    }
}
