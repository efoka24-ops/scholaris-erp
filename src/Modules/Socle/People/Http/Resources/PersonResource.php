<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http\Resources;

final class PersonResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'first_name', 'last_name', 'birth_date', 'birth_place', 'gender', 'nationality', 'phone', 'email', 'locale', 'national_id', 'has_photo', 'created_at', 'updated_at'];
    }

    public function toArray(array $row): array
    {
        $row['has_photo'] = ($row['photo_path'] ?? '') !== '';

        return parent::toArray($row);
    }
}
