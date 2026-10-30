<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Throwable;

/**
 * Dépannage et partage de l'accès d'un utilisateur par la plateforme : liste des comptes d'un client (lecture seule,
 * sans donnée métier) ; code d'accès à usage unique (rendu une fois avec un récapitulatif et un message prêt à copier),
 * envoyé à la demande par e-mail ou SMS (mobile camerounais) ; renvoi du MÊME code tant qu'il est ouvert.
 * Le journal garde l'usage, le canal et le résultat de l'envoi ; jamais le code.
 */
final class UserAccessCodes
{
    public const SEND = ['none', 'email', 'sms'];
    private const MOBILE = '/^\+2376\d{8}$/';

    public function __construct(
        private readonly AccountAdministration $accounts,
        private readonly PlatformAudit $audit,
        private readonly Config $config,
        private readonly MailGateway $mail,
        private readonly SmsGateway $sms,
        private readonly LoggerInterface $logger,
        private readonly PlatformSettings $settings,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function users(string $tenantId): array
    {
        return array_map(static fn (array $user): array => [
            'id' => $user['user_id'],
            'person_id' => $user['person_id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'status' => $user['status'],
            'roles' => $user['roles'],
            'two_factor_enabled' => $user['two_factor_enabled'],
            'last_login_at' => $user['last_login_at'],
        ], $this->accounts->users($tenantId));
    }

    /**
     * Crée le code (l'ancien est invalidé) et l'envoie si demandé. `strict` faux (acceptation d'une demande) : un canal
     * indisponible n'empêche pas la création, il est signalé `unavailable`.
     *
     * @return array<string, mixed>
     */
    public function issue(string $tenantId, string $tenantName, string $userId, string $send, string $actorId, ?string $ip, bool $strict = true): array
    {
        $account = $this->account($tenantId, $userId, $actorId);
        $unavailable = $send !== 'none' && !in_array($send, self::channels($account), true);
        if ($unavailable && $strict) {
            throw HttpException::validation(['send' => [self::unavailable($send)]]);
        }
        // Durée réglée par le super admin (Plateforme → Réglages), 24 heures par défaut.
        $issued = $this->accounts->issueAccessCode($tenantId, $userId, $actorId, $this->settings->value(PlatformSettings::ACCESS_CODE_TTL) * 60);
        $share = $this->share($account, $tenantName, $issued['code'], $issued['purpose'] === 'reset', $issued['expires_in']);
        $delivery = match (true) {
            $unavailable => ['channel' => $send, 'status' => 'unavailable', 'masked_to' => null],
            $send === 'none' => ['channel' => 'none', 'status' => 'not_sent', 'masked_to' => null],
            default => $this->deliver($account, $send, $share['message']),
        };
        $this->record($tenantId, $actorId, 'platform.access_code_issued', $userId, ['purpose' => $issued['purpose'], 'send' => $send, 'delivery' => $delivery['status']], $ip);

        return $issued + ['delivery' => $delivery, 'share' => $share];
    }

    /**
     * Renvoie le code encore ouvert (saisi depuis l'écran qui l'affiche) par e-mail ou SMS, sans le régénérer.
     *
     * @return array{delivery: array{channel: string, status: string, masked_to: ?string}}
     */
    public function send(string $tenantId, string $tenantName, string $userId, string $channel, string $code, string $actorId, ?string $ip): array
    {
        $account = $this->account($tenantId, $userId, $actorId);
        if (!in_array($channel, self::channels($account), true)) {
            throw HttpException::validation(['channel' => [self::unavailable($channel)]]);
        }
        if (!$this->accounts->isOpenAccessCode($tenantId, $userId, $code)) {
            throw HttpException::validation(['code' => ['Ce code a expiré ou a été remplacé : créez-en un nouveau.']], 'Code expiré.');
        }
        $reset = $account['status'] !== 'pending';
        $share = $this->share($account, $tenantName, $code, $reset, 0);
        $delivery = $this->deliver($account, $channel, $share['message']);
        $this->record($tenantId, $actorId, 'platform.access_code_sent', $userId, ['purpose' => $reset ? 'reset' : 'activation', 'send' => $channel, 'delivery' => $delivery['status']], $ip);

        return ['delivery' => $delivery];
    }

    /** @return array{first_name: string, email: ?string, phone: ?string, locale: string, status: string} */
    private function account(string $tenantId, string $userId, string $actorId): array
    {
        if ($tenantId === PlatformAudit::PLATFORM_TENANT) {
            if ($userId === $actorId) {
                throw HttpException::conflict('Vous ne pouvez pas créer de code d\'accès pour votre propre compte.');
            }

            return $this->accounts->holder($tenantId, AdministratorAccounts::PLATFORM_ROLE, $userId);
        }
        foreach ($this->accounts->users($tenantId) as $user) {
            if ($user['user_id'] === $userId) {
                return $user;
            }
        }

        throw HttpException::notFound('Utilisateur introuvable dans ce client.');
    }

    /**
     * @param array{first_name: string, email: ?string, phone: ?string, locale: string, status: string} $account
     * @return array<string, mixed>&array{message: array{subject: string, text: string, sms: string}}
     */
    private function share(array $account, string $tenantName, string $code, bool $reset, int $ttl): array
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $login = (string) ($account['email'] ?? $account['phone'] ?? '');
        $url = $base . ($reset ? '/mot-de-passe-oublie' : '/activation');
        $locale = $account['locale'] === 'en' ? 'en' : 'fr';
        $message = AccessShareMessage::compose($locale, [
            'first_name' => $account['first_name'],
            'establishment_name' => $tenantName,
            'login' => $login,
            'code' => $code,
            'minutes' => intdiv($ttl > 0 ? $ttl : 300, 60),
            'url' => $url,
            'login_url' => $base . '/connexion',
            'reset' => $reset,
        ]);

        return [
            'establishment_name' => $tenantName,
            'login' => $login,
            'login_url' => $base . '/connexion',
            'activation_url' => $url,
            'locale' => $locale,
            'channels' => self::channels($account),
            'message' => $message,
        ];
    }

    /**
     * @param array{email: ?string, phone: ?string, locale: string} $account
     * @param array{subject: string, text: string, sms: string} $message
     * @return array{channel: string, status: string, masked_to: ?string}
     */
    private function deliver(array $account, string $channel, array $message): array
    {
        $to = (string) ($channel === 'email' ? $account['email'] : $account['phone']);
        try {
            if ($channel === 'email') {
                $this->mail->send($to, $message['subject'], $message['text']);
            } else {
                $this->sms->send($to, $message['sms']);
            }
            $status = 'sent';
        } catch (Throwable $e) {
            $this->logger->warning('Code d\'accès non envoyé', ['channel' => $channel, 'error' => $e::class]);
            $status = 'failed';
        }

        return ['channel' => $channel, 'status' => $status, 'masked_to' => $channel === 'email' ? self::maskEmail($to) : self::maskPhone($to)];
    }

    /** @param array<string, mixed> $details */
    private function record(string $tenantId, string $actorId, string $action, string $userId, array $details, ?string $ip): void
    {
        $this->audit->record($tenantId === PlatformAudit::PLATFORM_TENANT ? null : $tenantId, $actorId, $action, 'user', $userId, $details, $ip);
    }

    /**
     * @param array{email: ?string, phone: ?string} $account
     * @return list<string>
     */
    private static function channels(array $account): array
    {
        return array_values(array_filter([
            $account['email'] !== null ? 'email' : null,
            $account['phone'] !== null && preg_match(self::MOBILE, $account['phone']) === 1 ? 'sms' : null,
        ]));
    }

    private static function unavailable(string $channel): string
    {
        return $channel === 'sms' ? 'Ce compte n\'a pas de numéro de mobile camerounais (+237 6…).' : 'Ce compte n\'a pas d\'adresse e-mail.';
    }

    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false || $at < 1 ? '•••' : mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    private static function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? substr($phone, 0, 5) . str_repeat('•', max(0, strlen($phone) - 9)) . substr($phone, -4) : '••••';
    }
}
