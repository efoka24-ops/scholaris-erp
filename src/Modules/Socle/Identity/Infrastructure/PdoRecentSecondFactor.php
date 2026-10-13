<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;

/**
 * La 2FA est enregistrée sur la session (`access_tokens.two_factor_verified_at`) ; les dates ISO se comparent en texte.
 * Quand la 2FA est désactivée par le réglage d'exploitation, la preuve est réputée acquise, SAUF pour l'administrateur
 * de plateforme, dont la 2FA est obligatoire en toutes circonstances.
 */
final class PdoRecentSecondFactor extends PdoRepository implements RecentSecondFactor
{
    public function __construct(
        ConnectionFactory $connections,
        private readonly Moment $moment,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly PdoUserRepository $users,
        private readonly PermissionResolver $resolver,
    ) {
        parent::__construct($connections);
    }

    public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool
    {
        if (!$this->twoFactorPolicy->enforced() && !$this->isPlatformAdmin($tenantId, $userId)) {
            return true;
        }
        if ($maxAgeSeconds < 0) {
            return false;
        }

        return $this->one(
            'SELECT 1 AS x FROM access_tokens WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ? AND two_factor_verified_at IS NOT NULL AND two_factor_verified_at >= ? LIMIT 1',
            [$tenantId, $userId, $this->moment->iso(), $this->moment->in(-$maxAgeSeconds)],
        ) !== null;
    }

    private function isPlatformAdmin(string $tenantId, string $userId): bool
    {
        $user = $this->users->find($tenantId, $userId);

        return $user !== null && $this->resolver->isPlatformAdmin($tenantId, $user->personId);
    }
}
