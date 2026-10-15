<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;

/** Autorisations du module Billing. Refus par défaut : sans permission explicite accordée par Identity, la réponse est 403. */
final class BillingPolicy
{
    public const READ = 'billing.read';
    public const MANAGE = 'billing.manage';
    public const CASH = 'billing.cash';
    public const REVERSAL_REQUEST = 'billing.reversal.request';
    public const REVERSAL_APPROVE = 'billing.reversal.approve';
    /** Libre-service (portails famille et apprenant) : paiement mobile money de SES factures ou références, rien d'autre. */
    public const SELF_PAY = 'billing.self.pay';
    /** Libre-service : lecture de SES références, paiements et reçus. */
    public const SELF_READ = 'billing.self.read';

    public function __construct(private readonly PermissionChecker $permissions)
    {
    }

    public function can(Principal $principal, string $establishmentId, string ...$anyOf): bool
    {
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, $establishmentId)) {
                return true;
            }
        }

        return false;
    }

    /** Lecture : consultation, gestion ou caisse. */
    public function authorizeRead(Principal $principal, string $establishmentId): void
    {
        $this->authorize($principal, $establishmentId, self::READ, self::MANAGE, self::CASH);
    }

    /** Lecture par le personnel (consultation, gestion ou caisse), sans refus. */
    public function canRead(Principal $principal, string $establishmentId): bool
    {
        return $this->can($principal, $establishmentId, self::READ, self::MANAGE, self::CASH);
    }

    public function authorize(Principal $principal, string $establishmentId, string ...$anyOf): void
    {
        if (!$this->can($principal, $establishmentId, ...$anyOf)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit d\'effectuer cette opération de facturation.');
        }
    }
}
