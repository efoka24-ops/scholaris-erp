<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\PermissionResolver;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Domain\Role;

/**
 * « Confirmation récente » des actions sensibles (le nom du contrat est historique). La preuve est enregistrée sur la session
 * (`access_tokens`) ; les dates ISO se comparent en texte :
 *  - double authentification validée dans la fenêtre (`two_factor_verified_at`) : valable pour tout le monde ;
 *  - SINON, pour une personne qui n'a PAS de double authentification : mot de passe saisi dans la fenêtre — à la connexion ou
 *    par POST /auth/reconfirm (`password_confirmed_at`) ;
 *  - l'administrateur de plateforme, lui, n'a que la double authentification : jamais le mot de passe.
 * Le réglage d'exploitation AUTH_2FA_REQUIRED n'accorde plus de dispense : sans 2FA, c'est le mot de passe qui prouve.
 */
final class PdoRecentSecondFactor extends PdoRepository implements RecentSecondFactor
{
    public function __construct(
        ConnectionFactory $connections,
        private readonly Moment $moment,
        private readonly PdoUserRepository $users,
        private readonly PermissionResolver $resolver,
    ) {
        parent::__construct($connections);
    }

    public function hasRecent(string $tenantId, string $userId, int $maxAgeSeconds): bool
    {
        if ($maxAgeSeconds < 0) {
            return false;
        }
        $user = $this->users->find($tenantId, $userId);
        if ($user === null) {
            return false;
        }
        $now = $this->moment->iso();
        $since = $this->moment->in(-$maxAgeSeconds);
        $base = 'SELECT 1 AS x FROM access_tokens WHERE tenant_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ? AND ';

        if ($this->one($base . 'two_factor_verified_at IS NOT NULL AND two_factor_verified_at >= ? LIMIT 1', [$tenantId, $userId, $now, $since]) !== null) {
            return true;
        }
        if ($user->hasTwoFactor() || ($tenantId === Role::PLATFORM_TENANT && $this->resolver->isPlatformAdmin($tenantId, $user->personId))) {
            return false;
        }

        return $this->one($base . 'password_confirmed_at IS NOT NULL AND password_confirmed_at >= ? LIMIT 1', [$tenantId, $userId, $now, $since]) !== null;
    }
}
