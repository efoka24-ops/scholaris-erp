<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\ProvisionalPassword;
use Scholaris\Modules\Socle\Identity\Domain\Role;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoBackupCodeRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoDeviceRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoLoginThrottleRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoOneTimeCodeRepository;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository;

/**
 * Mot de passe provisoire du personnel d'un client : création d'un compte directement utilisable, et ré-émission quand la
 * personne a perdu son accès. Le mot de passe est généré ici (voir ProvisionalPassword), haché Argon2id, rendu UNE fois à
 * l'appelant (qui le remet à la personne), jamais stocké en clair, jamais journalisé. La personne doit le remplacer à sa
 * première connexion (users.must_change_password).
 */
final class ProvisionalPasswordService
{
    /** Ré-émissions autorisées par compte et par heure. */
    public const MAX_REISSUES_PER_HOUR = 5;

    public function __construct(
        private readonly AccountProvisioner $provisioner,
        private readonly PdoUserRepository $users,
        private readonly PdoAccessTokenRepository $tokens,
        private readonly PdoDeviceRepository $devices,
        private readonly PdoOneTimeCodeRepository $codes,
        private readonly PdoBackupCodeRepository $backupCodes,
        private readonly PdoLoginThrottleRepository $throttle,
        private readonly PermissionResolver $resolver,
        private readonly PasswordHasher $hasher,
        private readonly AuditLog $audit,
        private readonly Moment $moment,
    ) {
    }

    /**
     * @return array{person_id: string, user_id: string, person_linked: bool, login: string, status: string, must_change_password: bool, provisional_password: string}
     */
    public function create(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $actorId, ?string $personId = null): array
    {
        if ($tenantId === '' || $tenantId === Role::PLATFORM_TENANT) {
            throw HttpException::validation(['tenant_id' => ['Client invalide.']]);
        }
        $ids = $this->provisioner->provision($tenantId, $firstName, $lastName, $phone, $email, $locale, $personId);
        $user = $this->users->find($tenantId, $ids['user_id']) ?? throw HttpException::notFound();
        $password = ProvisionalPassword::generate();
        $now = $this->moment->iso();
        $this->users->setProvisionalPassword($tenantId, $user->id, $this->hasher->hash($password), $now);
        $this->audit->append($tenantId, $actorId, 'identity.provisional_password_issued', 'user', $user->id, null, ['reason' => 'account_created', 'person_id' => $ids['person_id']]);

        return [
            'person_id' => $ids['person_id'],
            'user_id' => $ids['user_id'],
            'person_linked' => $ids['person_linked'] ?? false,
            'login' => (string) ($user->email ?? $user->phone ?? ''),
            'status' => User::ACTIVE,
            'must_change_password' => true,
            'provisional_password' => $password,
        ];
    }

    /**
     * Nouveau mot de passe provisoire : l'ancien est effacé, toutes les sessions et les codes ouverts sont invalidés, les compteurs
     * d'échec de connexion remis à zéro. `resetTwoFactor` efface aussi l'authentificateur (téléphone perdu).
     *
     * Garde-fous : jamais son propre compte ; jamais un compte bloqué ; un compte d'administration du client (rôle de niveau
     * client) seulement par un super administrateur ; quand `establishmentId` est donné, la personne doit exercer un rôle dans
     * cet établissement (ou n'en avoir encore aucun). Au plus 5 ré-émissions par heure et par compte (429).
     *
     * @return array{user_id: string, login: string, status: string, must_change_password: bool, provisional_password: string}
     */
    public function reissue(string $tenantId, string $userId, string $actorId, ?string $establishmentId = null, bool $resetTwoFactor = false): array
    {
        if ($tenantId === '' || $tenantId === Role::PLATFORM_TENANT) {
            throw HttpException::notFound('Utilisateur introuvable dans ce client.');
        }
        // L'identifiant peut être celui du compte ou celui de la personne (l'écran des rôles ne connaît que la personne) ; les ULID
        // sont uniques : aucune ambiguïté, et la recherche reste bornée au client.
        $user = $this->users->find($tenantId, $userId) ?? $this->users->findByPerson($tenantId, $userId) ?? throw HttpException::notFound('Utilisateur introuvable dans ce client.');
        $userId = $user->id;
        if ($userId === $actorId) {
            throw HttpException::conflict('Vous ne pouvez pas réinitialiser votre propre accès : utilisez « Mot de passe oublié ».');
        }
        if ($user->status === User::LOCKED) {
            throw HttpException::conflict('Ce compte est bloqué : il se rouvre par « Mot de passe oublié ».');
        }
        $this->assertMayReissue($user, $actorId, $establishmentId);
        $this->assertQuota($user);

        $password = ProvisionalPassword::generate();
        $now = $this->moment->iso();
        $this->users->setProvisionalPassword($tenantId, $userId, $this->hasher->hash($password), $now);
        if ($resetTwoFactor) {
            $this->users->clearTwoFactor($tenantId, $userId, $now);
            $this->backupCodes->replaceAll($tenantId, $userId, [], $now);
        }
        $this->tokens->revokeForUser($tenantId, $userId, $now);
        $this->devices->revokeAll($tenantId, $userId, $now);
        $this->codes->consumeOpen($tenantId, $userId, [ActivationService::ACTIVATION, ActivationService::RESET], $now);
        $this->clearFailures($user, $now);
        $this->audit->append($tenantId, $actorId, 'identity.provisional_password_issued', 'user', $userId, ['status' => $user->status], [
            'reason' => 'reissued',
            'two_factor_reset' => $resetTwoFactor,
        ]);

        return [
            'user_id' => $userId,
            'login' => (string) ($user->email ?? $user->phone ?? ''),
            'status' => User::ACTIVE,
            'must_change_password' => true,
            'provisional_password' => $password,
        ];
    }

    private function assertMayReissue(User $target, string $actorId, ?string $establishmentId): void
    {
        $actor = $this->users->find($target->tenantId, $actorId);
        $actorIsSuper = $actor !== null && $this->resolver->hasRole($actor->tenantId, $actor->personId, Role::SUPER_ADMIN);
        if ($actorIsSuper) {
            return;
        }
        $assignments = $this->resolver->activeAssignments($target->tenantId, $target->personId);
        foreach ($assignments as $assignment) {
            if ($assignment->isTenantWide()) {
                throw HttpException::forbidden('Le compte d\'un administrateur du client ne se réinitialise que par un super administrateur.');
            }
        }
        if ($establishmentId !== null && $assignments !== [] && $this->resolver->roleCodes($target->tenantId, $target->personId, $establishmentId) === []) {
            throw HttpException::notFound('Utilisateur introuvable dans ce client.');
        }
    }

    private function assertQuota(User $user): void
    {
        $key = hash('sha256', 'provisional|' . $user->id);
        $state = $this->throttle->state($key);
        $now = $this->moment->iso();
        if ($state['otp_window_start'] === null || $this->moment->secondsBetween($state['otp_window_start'], $now) >= 3600) {
            $state['otp_window_start'] = $now;
            $state['otp_count'] = 0;
        }
        if ($state['otp_count'] >= self::MAX_REISSUES_PER_HOUR) {
            throw HttpException::tooManyRequests(max(1, 3600 - $this->moment->secondsBetween($state['otp_window_start'], $now)));
        }
        $state['otp_count']++;
        $this->throttle->save($key, $state, $now);
    }

    private function clearFailures(User $user, string $now): void
    {
        foreach ([$user->phone, $user->email] as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $key = Identifier::fromInput($value)->key();
            $state = $this->throttle->state($key);
            $state['failed_count'] = 0;
            $state['locked_until'] = null;
            $this->throttle->save($key, $state, $now);
        }
    }
}
