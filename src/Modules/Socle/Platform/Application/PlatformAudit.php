<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Journal des actions de la plateforme : toujours dans le journal du client technique `platform`, et en plus dans celui
 * du client concerné (le client voit qui a ouvert, suspendu ou fermé ses applications).
 */
final class PlatformAudit
{
    public const PLATFORM_TENANT = 'platform';

    public function __construct(private readonly AuditLog $audit)
    {
    }

    /** @param array<string, mixed> $details */
    public function record(?string $tenantId, ?string $actorId, string $action, string $objectType, string $objectId, array $details = [], ?string $ip = null): void
    {
        $this->audit->append(self::PLATFORM_TENANT, $actorId, $action, $objectType, $objectId, null, $details + ($tenantId === null ? [] : ['tenant_id' => $tenantId]), $ip);
        if ($tenantId !== null && $tenantId !== self::PLATFORM_TENANT) {
            $this->audit->append($tenantId, $actorId, $action, $objectType, $objectId, null, $details, $ip);
        }
    }
}
