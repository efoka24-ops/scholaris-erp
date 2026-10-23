<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Scholaris\Modules\Socle\Organisation\Http\Requests\RoomRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\RoomResource;

final class RoomController extends ScopedCrudController
{
    protected function table(): string
    {
        return 'rooms';
    }

    protected function objectType(): string
    {
        return 'room';
    }

    protected function validator(): ValidatedRequest
    {
        return new RoomRequest();
    }

    protected function resource(): Resource
    {
        return new RoomResource();
    }

    protected function filters(): array
    {
        return ['name'];
    }

    protected function sorts(): array
    {
        return ['name', 'capacity'];
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        if ($existing === null) {
            $changes += ['equipment' => []];
        }
        if (array_key_exists('equipment', $changes)) {
            $changes['equipment'] = json_encode($changes['equipment'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $changes;
    }
}
