<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Resources;

/** Ressource de demande de congé, à liste blanche : jamais tenant_id. */
final class LeaveResources
{
    public static function request(): Resource
    {
        return new class () extends Resource {
            protected function fields(): array
            {
                return [
                    'id', 'establishment_id', 'staff_id', 'leave_type', 'starts_on', 'ends_on', 'days', 'reason', 'status',
                    'approval_id', 'requested_by', 'decided_by', 'decided_at', 'created_at', 'updated_at',
                ];
            }
        };
    }
}
