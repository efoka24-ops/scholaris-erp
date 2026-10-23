<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/** Journal d'audit inaltérable (chaîné par empreinte). Seule l'écriture par ajout est possible. */
interface AuditLog
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return int numéro de séquence de l'entrée dans le journal du client
     */
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
    ): int;

    /** Recalcule toute la chaîne d'un client. */
    public function verify(string $tenantId): AuditVerification;
}
