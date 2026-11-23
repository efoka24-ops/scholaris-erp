<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Audit\Contracts\AuditVerification;

/** Doublure du journal d'audit : garde les actions journalisées (avec avant/après). */
final class RecordingAuditLog implements AuditLog
{
    /** @var list<array{tenant: string, actor: ?string, action: string, type: string, id: string, before: ?array<string, mixed>, after: ?array<string, mixed>}> */
    public array $entries = [];

    public function append(
        string $tenantId,
        ?string $actorId,
        string $action,
        string $objectType,
        string $objectId,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
        ?string $device = null,
    ): int {
        $this->entries[] = ['tenant' => $tenantId, 'actor' => $actorId, 'action' => $action, 'type' => $objectType, 'id' => $objectId, 'before' => $before, 'after' => $after];

        return count($this->entries);
    }

    public function verify(string $tenantId): AuditVerification
    {
        throw new \LogicException('Non utilisé par les tests Nursery.');
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_column($this->entries, 'action');
    }
}
