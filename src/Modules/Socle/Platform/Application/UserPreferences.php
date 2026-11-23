<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Réglages personnels (C-112 point 6) : canaux de notification en plus de l'application (SMS, e-mail) et résumé quotidien par
 * e-mail (membres de la plateforme). Sans ligne enregistrée : application seulement, pas de résumé.
 */
final class UserPreferences
{
    public function __construct(private readonly PlatformDb $db, private readonly PlatformClock $clock)
    {
    }

    /** @return array{channels: array{app: bool, sms: bool, email: bool}, daily_digest: bool} */
    public function of(string $tenantId, string $userId): array
    {
        $row = $this->db->one('SELECT notify_sms, notify_email, daily_digest FROM platform_user_preferences WHERE tenant_id = ? AND user_id = ?', [$tenantId, $userId]);

        return [
            'channels' => ['app' => true, 'sms' => (int) ($row['notify_sms'] ?? 0) === 1, 'email' => (int) ($row['notify_email'] ?? 0) === 1],
            'daily_digest' => (int) ($row['daily_digest'] ?? 0) === 1,
        ];
    }

    /**
     * @param array{sms?: bool, email?: bool, daily_digest?: bool} $changes
     * @return array{channels: array{app: bool, sms: bool, email: bool}, daily_digest: bool}
     */
    public function update(string $tenantId, string $userId, string $personId, array $changes): array
    {
        $current = $this->of($tenantId, $userId);
        $values = [
            'person_id' => $personId,
            'notify_sms' => (int) ($changes['sms'] ?? $current['channels']['sms']),
            'notify_email' => (int) ($changes['email'] ?? $current['channels']['email']),
            'daily_digest' => (int) ($changes['daily_digest'] ?? $current['daily_digest']),
            'updated_at' => $this->clock->iso(),
        ];
        if ($this->db->update('platform_user_preferences', $values, ['tenant_id' => $tenantId, 'user_id' => $userId]) === 0) {
            $this->db->insert('platform_user_preferences', ['tenant_id' => $tenantId, 'user_id' => $userId] + $values);
        }

        return $this->of($tenantId, $userId);
    }

    /** @return list<string> canaux supplémentaires choisis par la personne (« email », « sms ») */
    public function extraChannelsForPerson(string $tenantId, string $personId): array
    {
        $row = $this->db->one('SELECT notify_sms, notify_email FROM platform_user_preferences WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);
        $out = [];
        if ((int) ($row['notify_email'] ?? 0) === 1) {
            $out[] = 'email';
        }
        if ((int) ($row['notify_sms'] ?? 0) === 1) {
            $out[] = 'sms';
        }

        return $out;
    }

    /** @return list<string> comptes de la plateforme abonnés au résumé quotidien */
    public function digestSubscribers(): array
    {
        return array_map(static fn (array $r): string => (string) $r['user_id'], $this->db->all('SELECT user_id FROM platform_user_preferences WHERE tenant_id = ? AND daily_digest = 1 ORDER BY user_id', [PlatformAudit::PLATFORM_TENANT]));
    }
}
