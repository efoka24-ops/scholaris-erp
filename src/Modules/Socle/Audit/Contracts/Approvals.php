<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Validation à deux personnes (EX-S13-02) pour les opérations sensibles : correction de note verrouillée,
 * annulation de paiement, modification de barème. Celui qui demande ne peut jamais valider.
 */
interface Approvals
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** @return string identifiant de la demande */
    public function request(string $tenantId, string $kind, string $subjectType, string $subjectId, string $reason, string $requestedBy): string;

    /** @throws \Scholaris\Core\Http\HttpException 403 si l'approbateur est le demandeur, 409 si déjà décidée */
    public function approve(string $tenantId, string $approvalId, string $approverId): void;

    /** @throws \Scholaris\Core\Http\HttpException */
    public function reject(string $tenantId, string $approvalId, string $approverId): void;

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $approvalId): ?array;
}
