<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Scholaris\Modules\Socle\Organisation\Http\Requests\OrgUnitRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\OrgUnitResource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;

/** Unités internes d'un établissement (hiérarchie par parent_id : établissement > unité > sous-unité). */
final class OrgUnitController extends ScopedCrudController
{
    protected function table(): string
    {
        return 'org_units';
    }

    protected function objectType(): string
    {
        return 'org_unit';
    }

    protected function validator(): ValidatedRequest
    {
        return new OrgUnitRequest();
    }

    protected function resource(): Resource
    {
        return new OrgUnitResource();
    }

    protected function filters(): array
    {
        return ['type', 'parent_id'];
    }

    protected function sorts(): array
    {
        return ['name', 'type', 'created_at'];
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        $parentId = $changes['parent_id'] ?? null;
        if ($parentId !== null) {
            // Le dépôt est borné au client et à l'établissement actif : une unité d'un autre établissement est « introuvable ».
            $units = $this->repository()->all();
            $byId = [];
            foreach ($units as $unit) {
                $byId[(string) $unit['id']] = $unit;
            }
            if (!isset($byId[(string) $parentId])) {
                $this->fail(['parent_id' => ['Unité parente introuvable dans cet établissement.']]);
            }
            if ($existing !== null) {
                // Refuse un cycle : le parent ne peut être l'unité elle-même ni l'un de ses descendants.
                for ($cursor = (string) $parentId, $hops = 0; $cursor !== '' && $hops < 100; $hops++) {
                    if ($cursor === (string) $existing['id']) {
                        $this->fail(['parent_id' => ['Une unité ne peut pas dépendre d\'elle-même ni de l\'une de ses sous-unités.']]);
                    }
                    $cursor = (string) ($byId[$cursor]['parent_id'] ?? '');
                }
            }
        }

        return $changes;
    }
}
