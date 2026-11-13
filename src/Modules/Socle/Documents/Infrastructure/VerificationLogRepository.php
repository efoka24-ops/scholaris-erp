<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Journal des consultations publiques : jeton, résultat, date, adresse IP hachée (jamais en clair). */
final class VerificationLogRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function record(string $id, string $tenantId, string $token, string $outcome, ?string $ip, string $at): void
    {
        $this->db->insert('verification_log', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'token' => substr($token, 0, 64),
            'outcome' => $outcome,
            'ip_hash' => hash('sha256', 'scholaris-verify|' . ($ip ?? '')),
            'consulted_at' => $at,
        ]);
    }
}
