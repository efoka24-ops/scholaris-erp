<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use RuntimeException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Contracts\AuditVerification;

/** Journal d'audit qui peut échouer sur une action donnée, pour simuler une panne en cours de transaction. */
final class FlakyAudit implements AuditLog
{
    public ?string $failOn = null;

    public function __construct(private readonly AuditLog $inner)
    {
    }

    public function append(string $tenantId, ?string $actorId, string $action, string $objectType, string $objectId, ?array $before = null, ?array $after = null, ?string $ip = null, ?string $device = null): int
    {
        if ($this->failOn === $action) {
            throw new RuntimeException('Panne simulée du journal d\'audit.');
        }

        return $this->inner->append($tenantId, $actorId, $action, $objectType, $objectId, $before, $after, $ip, $device);
    }

    public function verify(string $tenantId): AuditVerification
    {
        return $this->inner->verify($tenantId);
    }
}
