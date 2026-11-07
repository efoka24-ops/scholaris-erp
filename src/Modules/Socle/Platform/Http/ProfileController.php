<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\AccountActivity;
use Scholaris\Modules\Socle\Identity\Contracts\OwnAccount;
use Scholaris\Modules\Socle\Identity\Contracts\SecurityPolicies;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Application\UserPreferences;

/**
 * « Mon profil » et réglages personnels (C-112 point 6), pour tout utilisateur connecté (plateforme comprise) : identité,
 * téléphone, e-mail, langue, sessions ouvertes et « déconnecter les autres appareils », canaux de notification, résumé
 * quotidien (membres de la plateforme). Réglages de sécurité de la plateforme (2FA par rôle, durée de session) : super
 * administrateur seul (platform.settings.manage, 2FA récente).
 */
final class ProfileController
{
    private const NAME = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PlatformGuard $guard,
        private readonly OwnAccount $account,
        private readonly AccountActivity $activity,
        private readonly UserPreferences $preferences,
        private readonly SecurityPolicies $policies,
        private readonly PlatformAudit $audit,
    ) {
    }

    /** GET /me/profile */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->present($this->guard->principal($request))]);
    }

    /** PATCH /me/profile { first_name?, last_name?, phone?, email?, locale? } */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->principal($request);
        $this->assertPerson($principal);
        $input = Input::of($request, ['first_name', 'last_name', 'phone', 'email', 'locale']);
        $changes = [];
        foreach (['first_name', 'last_name'] as $field) {
            if ($input->has($field)) {
                $changes[$field] = (string) $input->text($field, 1, 80, true, self::NAME);
            }
        }
        if ($input->has('phone')) {
            $raw = $input->phone('phone', false);
            $changes['phone'] = $raw === null ? null : \Scholaris\Modules\Socle\Platform\Domain\CameroonPhone::normalize($raw);
            if ($raw !== null && $changes['phone'] === null) {
                $input->fail('phone', 'Numéro de mobile camerounais attendu (+237 6XX XX XX XX).');
            }
        }
        if ($input->has('email')) {
            $changes['email'] = $input->email('email', false);
        }
        if ($input->has('locale')) {
            $changes['locale'] = (string) $input->choice('locale', ['fr', 'en']);
        }
        $input->validate();
        $this->account->update($principal->tenantId, $principal->userId, $changes);

        return $this->responder->json(['data' => $this->present($principal)]);
    }

    /** PATCH /me/preferences { notify_sms?, notify_email?, daily_digest? } */
    public function preferences(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->principal($request);
        $this->assertPerson($principal);
        $input = Input::of($request, ['notify_sms', 'notify_email', 'daily_digest']);
        $changes = [];
        foreach (['notify_sms' => 'sms', 'notify_email' => 'email', 'daily_digest' => 'daily_digest'] as $field => $key) {
            if ($input->has($field)) {
                $value = $input->raw($field);
                if (!is_bool($value)) {
                    $input->fail($field, 'Booléen attendu.');
                    continue;
                }
                $changes[$key] = $value;
            }
        }
        if (($changes['daily_digest'] ?? false) === true && $principal->tenantId !== PlatformAudit::PLATFORM_TENANT) {
            $input->fail('daily_digest', 'Le résumé quotidien est réservé aux rôles de la plateforme.');
        }
        $input->validate();
        $person = $this->activity->account($principal->tenantId, $principal->userId)['person_id'];
        $this->preferences->update($principal->tenantId, $principal->userId, $person, $changes);

        return $this->responder->json(['data' => $this->present($principal)]);
    }

    /** POST /me/sessions/logout-others : déconnecte tous les autres appareils. */
    public function logoutOthers(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->principal($request);
        $this->assertPerson($principal);
        Input::of($request, [])->validate();

        return $this->responder->json(['data' => ['revoked_sessions' => $this->account->revokeOtherSessions($principal->tenantId, $principal->userId)]]);
    }

    /** GET /platform/settings/security */
    public function security(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->platformAny($request, ['platform.settings.read', 'platform.settings.manage']);

        return $this->responder->json(['data' => $this->securityData()]);
    }

    /** PATCH /platform/settings/security { session_hours?, two_factor?: { <rôle>: bool } } (2FA récente) */
    public function updateSecurity(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->guard->platformAdmin($request, 'platform.settings.manage', true);
        $input = Input::of($request, ['session_hours', 'two_factor']);
        $hours = $input->has('session_hours') ? $input->integer('session_hours', SecurityPolicies::SESSION_HOURS_MIN, SecurityPolicies::SESSION_HOURS_MAX) : null;
        $roles = $input->raw('two_factor');
        $twoFactor = [];
        if ($roles !== null) {
            if (!is_array($roles)) {
                $input->fail('two_factor', 'Objet { rôle: booléen } attendu.');
            } else {
                foreach ($roles as $role => $required) {
                    if (!is_string($role) || !is_bool($required)) {
                        $input->fail('two_factor', 'Objet { rôle: booléen } attendu.');
                        break;
                    }
                    $twoFactor[$role] = $required;
                }
            }
        }
        $input->validate();
        if ($hours !== null) {
            $this->policies->setSessionHours($hours, $principal->userId);
        }
        foreach ($twoFactor as $role => $required) {
            $this->policies->setTwoFactorRequired($role, $required, $principal->userId);
        }
        $this->audit->record(null, $principal->userId, 'platform.security_settings_changed', 'setting', 'security', ['session_hours' => $hours, 'two_factor' => $twoFactor], PlatformGuard::ip($request));

        return $this->responder->json(['data' => $this->securityData()]);
    }

    /** @return array<string, mixed> */
    private function securityData(): array
    {
        return [
            'session_hours' => ['value' => $this->policies->sessionHours(), 'default' => SecurityPolicies::SESSION_HOURS_DEFAULT, 'min' => SecurityPolicies::SESSION_HOURS_MIN, 'max' => SecurityPolicies::SESSION_HOURS_MAX],
            'roles' => $this->policies->roles(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Principal $principal): array
    {
        $this->assertPerson($principal);

        return $this->account->profile($principal->tenantId, $principal->userId) + [
            'platform_member' => $principal->tenantId === PlatformAudit::PLATFORM_TENANT,
            'sessions' => $this->account->sessions($principal->tenantId, $principal->userId),
            'preferences' => $this->preferences->of($principal->tenantId, $principal->userId),
        ];
    }

    private function assertPerson(Principal $principal): void
    {
        if (str_starts_with($principal->userId, 'svc:')) {
            throw \Scholaris\Core\Http\HttpException::forbidden('Un compte de service n\'a pas de profil.');
        }
    }
}
