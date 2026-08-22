<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Referentiel des permissions et des roles metier.
 *
 * La matrice vit dans database/rbac-matrix.php, genere depuis le seed de la
 * version Node : ce seeder ne fait que la projeter en base. Idempotent, il peut
 * etre rejoue apres chaque ajout de permission.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $matrix = require database_path('rbac-matrix.php');

        $permissionIds = $this->syncPermissions($matrix['permissions']);

        // Le Super Admin porte toutes les permissions et n'appartient a aucun
        // etablissement (tenant_id null) : il administre la plateforme entiere.
        $this->syncRole('SUPER_ADMIN', 'Administrateur de la plateforme', $permissionIds->values()->all(), true);

        foreach ($matrix['roles'] as $role) {
            $ids = collect($role['permissions'])
                ->map(fn (string $key) => $permissionIds->get($key))
                ->filter()
                ->values()
                ->all();

            $this->syncRole($role['name'], $role['description'], $ids, true);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $permissions
     * @return \Illuminate\Support\Collection<string, string> cle "resource:action" vers id
     */
    private function syncPermissions(array $permissions): \Illuminate\Support\Collection
    {
        foreach ($permissions as [$resource, $action, $description]) {
            Permission::query()->updateOrCreate(
                ['resource' => $resource, 'action' => $action],
                ['description' => $description],
            );
        }

        return Permission::query()
            ->get()
            ->mapWithKeys(fn (Permission $p) => ["{$p->resource}:{$p->action}" => $p->id]);
    }

    /**
     * @param  array<int, string>  $permissionIds
     */
    private function syncRole(string $name, string $description, array $permissionIds, bool $isSystem): void
    {
        // Roles definis au niveau plateforme (tenant_id null) : ils servent de
        // referentiel commun a tous les etablissements.
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => null, 'name' => $name],
            ['description' => $description, 'is_system' => $isSystem],
        );

        DB::table('role_permissions')->where('role_id', $role->id)->delete();

        if ($permissionIds === []) {
            return;
        }

        DB::table('role_permissions')->insert(
            array_map(
                fn (string $permissionId) => ['role_id' => $role->id, 'permission_id' => $permissionId],
                $permissionIds,
            )
        );
    }
}
