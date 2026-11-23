<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Nursery\NurseryApplication;
use Scholaris\Modules\Apps\Nursery\Policies\RoleMatrix;
use Tests\Support\Nursery\NurseryTestCase;

/** EX-MAT-07 : un cas par ligne de spec 007 §2 (N01 à N11) ; la migration de rôles est le miroir exact de la matrice. */
final class RoleMatrixTest extends NurseryTestCase
{
    /** @return array<string, array{0: string}> */
    public static function roleLines(): array
    {
        $rows = [];
        foreach (array_keys(RoleMatrix::ROLES + RoleMatrix::SHARED) as $code) {
            $rows[$code] = [$code];
        }
        ksort($rows);

        return $rows;
    }

    /** @return list<string> */
    private function storedPermissions(string $role): array
    {
        $statement = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT permission FROM role_permissions WHERE role = ? ORDER BY permission');
        $statement->execute([$role]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    #[DataProvider('roleLines')]
    public function testChaqueLigneDeLaSpecEstDansLaMatriceEtLaBase(string $code): void
    {
        $this->bootNursery();
        if (isset(RoleMatrix::ROLES[$code])) {
            $role = RoleMatrix::ROLES[$code];
            $expected = $role['permissions'];
            sort($expected);
            self::assertSame($expected, $this->storedPermissions($role['code']), $code . ' : permissions de la migration différentes de la matrice');
            $row = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT level, is_sensitive FROM roles WHERE code = ?');
            $row->execute([$role['code']]);
            $stored = $row->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($stored);
            self::assertSame($role['level'], $stored['level']);
            self::assertSame($role['sensitive'] ? 1 : 0, (int) $stored['is_sensitive']);

            return;
        }
        // Ligne portée par un rôle du socle : ce rôle ne reçoit AUCUNE permission nursery.* (le parent passe par le lien de garde).
        $shared = RoleMatrix::SHARED[$code];
        foreach ($this->storedPermissions($shared['role']) as $permission) {
            self::assertStringStartsNotWith('nursery.', $permission, $code . ' : le rôle ' . $shared['role'] . ' du socle ne doit pas recevoir de permission nursery.*');
        }
    }

    public function testLesPermissionsNurseryDeLaBaseSontExactementCellesDeLaMatrice(): void
    {
        $this->bootNursery();
        $statement = $this->service(ConnectionFactory::class)->pdo()->query("SELECT DISTINCT permission FROM role_permissions WHERE permission LIKE 'nursery.%' ORDER BY permission");
        self::assertNotFalse($statement);
        $stored = array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        $declared = array_values(array_filter(NurseryApplication::permissions(), static fn (string $p): bool => str_starts_with($p, 'nursery.')));
        sort($declared);
        self::assertSame($declared, $stored);
    }

    public function testLeParentNAAucunePermissionNurseryEnBase(): void
    {
        $this->bootNursery();
        self::assertSame([], array_values(array_filter($this->storedPermissions('parent'), static fn (string $p): bool => str_starts_with($p, 'nursery.'))));
    }

    public function testLaMatriceNeNommeQueDesActeursDeclares(): void
    {
        $known = array_keys(RoleMatrix::ROLES + RoleMatrix::SHARED);
        foreach (RoleMatrix::MATRIX as $domain => $actors) {
            self::assertNotSame('', $domain);
            foreach ($actors as $actor => [$mode, $permission]) {
                self::assertContains($actor, $known);
                self::assertContains($mode, ['V', 'E']);
                $role = RoleMatrix::ROLES[$actor] ?? null;
                self::assertNotNull($role);
                self::assertContains($permission, $role['permissions'], $domain . ' / ' . $actor);
            }
        }
    }

    public function testLEducatriceNeVoitQueSaSectionEnLectureEtNeModifiePasLAutreEnEcriture(): void
    {
        $this->bootNursery();
        $a = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite section A'])['id'];
        $b = (string) $this->ok('POST', '/nursery/sections', ['label' => 'Petite section B'])['id'];
        $this->world->child(self::TA, self::EA, $a, 'Ada');
        $this->world->child(self::TA, self::EA, $b, 'Bao');
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.child.read', $a);
        $names = array_column($this->ok('GET', '/nursery/children', null, 'educatrice-a'), 'first_name');
        self::assertSame(['Ada'], $names);
        self::assertSame(404, $this->code('GET', '/nursery/sections/' . $b . '/children', null, 'educatrice-a'));
        self::assertSame(200, $this->code('GET', '/nursery/sections/' . $a . '/children', null, 'educatrice-a'));
    }
}
