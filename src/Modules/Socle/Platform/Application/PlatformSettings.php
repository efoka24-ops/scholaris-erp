<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Réglages de la plateforme modifiables par le super admin depuis le back-office (table `platform_settings`, clé => valeur).
 * Réglages numériques : chacun a une valeur par défaut et des bornes ; une valeur absente ou hors bornes rend la valeur par
 * défaut. Contact et numéros de paiement mobile money de la plateforme : un seul réglage structuré (JSON) ; chaque champ vide
 * se replie sur les variables d'environnement PLATFORM_CONTACT_* et PLATFORM_PAY_* (rétrocompatibilité).
 */
final class PlatformSettings
{
    /** Durée de validité, en minutes, d'un code d'accès créé depuis le back-office (5 minutes à 7 jours). */
    public const ACCESS_CODE_TTL = 'access_code_ttl_minutes';

    /** Niveau de blocage par défaut d'un impayé (mode régularisation) : 0 = lecture et export seulement, 1 = tout bloqué sauf la régularisation. */
    public const REGULARISATION_LEVEL = 'regularisation_default_level';

    /** Jours avant qu'une déclaration de paiement non confirmée expire (puis relance du client). */
    public const REQUEST_EXPIRY_DAYS = 'regularisation_request_expiry_days';

    /** Écart toléré, en XAF, entre le montant déclaré (ou dû) et le montant reçu (frais d'opérateur). */
    public const AMOUNT_TOLERANCE = 'regularisation_amount_tolerance_xaf';

    /** Montant minimum, en XAF, d'un paiement partiel déclaré. */
    public const PARTIAL_MIN = 'regularisation_partial_min_xaf';

    /** Paiement partiel confirmé : 0 = la période est prolongée proportionnellement, 1 = le montant n'est que porté au crédit du solde. */
    public const PARTIAL_MODE = 'regularisation_partial_mode';

    private const CONTACT_KEY = 'platform_contact';
    private const DEFAULT_NAME = 'TRU GROUP';
    public const PAYMENT_CHANNELS = ['orange_money', 'mtn_momo'];
    private const ENV_PAYMENTS = [
        'orange_money' => ['PLATFORM_PAY_ORANGE_PHONE', 'PLATFORM_PAY_ORANGE_HOLDER'],
        'mtn_momo' => ['PLATFORM_PAY_MTN_PHONE', 'PLATFORM_PAY_MTN_HOLDER'],
    ];

    /** @var array<string, array{default: int, min: int, max: int}> */
    private const DEFINITIONS = [
        self::ACCESS_CODE_TTL => ['default' => 1440, 'min' => 5, 'max' => 10080],
        self::REGULARISATION_LEVEL => ['default' => 0, 'min' => 0, 'max' => 1],
        self::REQUEST_EXPIRY_DAYS => ['default' => 7, 'min' => 1, 'max' => 60],
        self::AMOUNT_TOLERANCE => ['default' => 100, 'min' => 0, 'max' => 10000],
        self::PARTIAL_MIN => ['default' => 1000, 'min' => 100, 'max' => 1000000],
        self::PARTIAL_MODE => ['default' => 0, 'min' => 0, 'max' => 1],
    ];

    public function __construct(
        private readonly PlatformDb $db,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return array<string, array{value: int, default: int, min: int, max: int, updated_at: ?string}> */
    public function all(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $row = $this->db->one('SELECT setting_value, updated_at FROM platform_settings WHERE setting_key = ?', [$key]);
            $out[$key] = ['value' => $this->value($key), 'updated_at' => $row === null ? null : (string) $row['updated_at']] + $definition;
        }

        return $out;
    }

    public function value(string $key): int
    {
        $definition = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException('Réglage inconnu : ' . $key);
        $raw = $this->db->scalar('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [$key]);
        $value = is_numeric($raw) ? (int) $raw : $definition['default'];

        return $value < $definition['min'] || $value > $definition['max'] ? $definition['default'] : $value;
    }

    /** @return list<string> clés des réglages numériques */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** @return array<string, array{value: int, default: int, min: int, max: int, updated_at: ?string}> */
    public function set(string $key, int $value, string $actorId, ?string $ip): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw HttpException::validation([$key => ['Réglage inconnu.']]);
        if ($value < $definition['min'] || $value > $definition['max']) {
            throw HttpException::validation([$key => [sprintf('Valeur attendue entre %d et %d.', $definition['min'], $definition['max'])]]);
        }
        $before = $this->value($key);
        $this->store($key, (string) $value, $actorId);
        $this->audit->record(null, $actorId, 'platform.setting_changed', 'setting', $key, ['before' => $before, 'after' => $value], $ip);

        return $this->all();
    }

    /**
     * Contact et numéros de paiement mobile money de la plateforme, tels qu'ils sont montrés aux clients : le réglage du
     * back-office, champ par champ, sinon la variable d'environnement, sinon rien (nom : « TRU GROUP »).
     *
     * @return array{name: string, email: ?string, phone: ?string, payments: list<array{channel: string, phone: string, holder: string}>}
     */
    public function contact(): array
    {
        $stored = $this->storedContact();
        $payments = [];
        foreach (self::PAYMENT_CHANNELS as $channel) {
            $phone = $stored['payments'][$channel]['phone'] ?? $this->env(self::ENV_PAYMENTS[$channel][0]);
            if ($phone === null) {
                continue;
            }
            $payments[] = ['channel' => $channel, 'phone' => $phone, 'holder' => $stored['payments'][$channel]['holder'] ?? $this->env(self::ENV_PAYMENTS[$channel][1]) ?? $stored['name'] ?? $this->env('PLATFORM_CONTACT_NAME') ?? self::DEFAULT_NAME];
        }

        return [
            'name' => $stored['name'] ?? $this->env('PLATFORM_CONTACT_NAME') ?? self::DEFAULT_NAME,
            'email' => $stored['email'] ?? $this->env('PLATFORM_CONTACT_EMAIL'),
            'phone' => $stored['phone'] ?? $this->env('PLATFORM_CONTACT_PHONE'),
            'payments' => $payments,
        ];
    }

    /**
     * Valeurs saisies dans le back-office (vides si non renseignées) et valeurs effectives.
     *
     * @return array{stored: array{name: ?string, email: ?string, phone: ?string, payments: list<array{channel: string, phone: string, holder: string}>}, effective: array{name: string, email: ?string, phone: ?string, payments: list<array{channel: string, phone: string, holder: string}>}, updated_at: ?string}
     */
    public function contactSettings(): array
    {
        $stored = $this->storedContact();
        $payments = [];
        foreach (self::PAYMENT_CHANNELS as $channel) {
            if (isset($stored['payments'][$channel])) {
                $payments[] = ['channel' => $channel] + $stored['payments'][$channel];
            }
        }
        $row = $this->db->one('SELECT updated_at FROM platform_settings WHERE setting_key = ?', [self::CONTACT_KEY]);

        return [
            'stored' => ['name' => $stored['name'] ?? null, 'email' => $stored['email'] ?? null, 'phone' => $stored['phone'] ?? null, 'payments' => $payments],
            'effective' => $this->contact(),
            'updated_at' => $row === null ? null : (string) $row['updated_at'],
        ];
    }

    /**
     * Remplace le contact (champs déjà validés par le contrôleur : e-mail valide, téléphones +237, au plus un numéro par moyen).
     *
     * @param array{name: ?string, email: ?string, phone: ?string, payments: list<array{channel: string, phone: string, holder: string}>} $contact
     */
    public function setContact(array $contact, string $actorId, ?string $ip): void
    {
        $before = $this->storedContact();
        $payments = [];
        foreach ($contact['payments'] as $payment) {
            $payments[$payment['channel']] = ['phone' => $payment['phone'], 'holder' => $payment['holder']];
        }
        $value = array_filter(['name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']], static fn (?string $v): bool => $v !== null)
            + ['payments' => $payments];
        $this->store(self::CONTACT_KEY, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $actorId);
        // Journal : jamais les numéros en clair, seulement les champs renseignés avant et après.
        $summary = static fn (array $c): array => [
            'name' => isset($c['name']), 'email' => isset($c['email']), 'phone' => isset($c['phone']), 'payments' => array_keys($c['payments'] ?? []),
        ];
        $this->audit->record(null, $actorId, 'platform.contact_changed', 'setting', self::CONTACT_KEY, ['before' => $summary($before), 'after' => $summary($value)], $ip);
    }

    private function store(string $key, string $value, string $actorId): void
    {
        $now = $this->clock->iso();
        if ($this->db->update('platform_settings', ['setting_value' => $value, 'updated_by' => $actorId, 'updated_at' => $now], ['setting_key' => $key]) === 0) {
            $this->db->insert('platform_settings', ['setting_key' => $key, 'setting_value' => $value, 'updated_by' => $actorId, 'updated_at' => $now]);
        }
    }

    /** @return array{name?: string, email?: string, phone?: string, payments: array<string, array{phone: string, holder: string}>} */
    private function storedContact(): array
    {
        $raw = $this->db->scalar('SELECT setting_value FROM platform_settings WHERE setting_key = ?', [self::CONTACT_KEY]);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['payments' => []];
        }
        $out = ['payments' => []];
        foreach (['name', 'email', 'phone'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                $out[$field] = trim($data[$field]);
            }
        }
        foreach (self::PAYMENT_CHANNELS as $channel) {
            $payment = $data['payments'][$channel] ?? null;
            if (is_array($payment) && is_string($payment['phone'] ?? null) && $payment['phone'] !== '') {
                $out['payments'][$channel] = ['phone' => $payment['phone'], 'holder' => is_string($payment['holder'] ?? null) ? $payment['holder'] : ''];
            }
        }

        return $out;
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
