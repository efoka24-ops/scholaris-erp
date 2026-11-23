<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use InvalidArgumentException;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;

final class PutSettingRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'scope' => Field::enum(array_map(static fn (SettingScope $scope): string => $scope->value, SettingScope::cases()))->required(),
            'key' => Field::custom(static function (mixed $value): string {
                if (!is_string($value) || preg_match(SettingsService::KEY_FORMAT, $value) !== 1) {
                    throw new InvalidArgumentException('La clé doit commencer par une lettre minuscule et ne contenir que des minuscules, chiffres, « . », « _ » ou « - » (100 caractères au plus).');
                }

                return $value;
            })->required(),
            'value' => Field::any()->required()->nullable(),
            'org_unit_id' => Field::reference()->nullable(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        $unit = $values['org_unit_id'] ?? null;
        if (($values['scope'] ?? null) === SettingScope::OrgUnit->value && $unit === null) {
            $errors['org_unit_id'] = ['L\'unité est obligatoire pour le niveau org_unit.'];
        }
        if ($unit !== null && ($values['scope'] ?? null) !== SettingScope::OrgUnit->value) {
            $errors['org_unit_id'] = ['L\'unité ne s\'indique que pour le niveau org_unit.'];
        }

        return $errors;
    }
}
