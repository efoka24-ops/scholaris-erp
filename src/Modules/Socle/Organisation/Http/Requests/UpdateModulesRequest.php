<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Organisation\Domain\ModuleCatalog;

final class UpdateModulesRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'modules' => Field::custom(static function (mixed $value): array {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new InvalidArgumentException('Ce champ doit être une liste de modules.');
                }
                $modules = [];
                foreach ($value as $module) {
                    if (!is_string($module) || !ModuleCatalog::isKnown($module)) {
                        throw new InvalidArgumentException('Module inconnu. Modules possibles : ' . implode(', ', ModuleCatalog::KEYS) . '.');
                    }
                    $modules[$module] = $module;
                }

                return array_values($modules);
            })->required(),
        ];
    }
}
