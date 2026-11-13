<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Mail;

use Scholaris\Core\Config\Config;

/** Réglages SMTP (config/mail.php, alimenté par MAIL_* du .env). Le mot de passe n'est jamais affiché ni journalisé. */
final class SmtpSettings
{
    public const NONE = 'none';
    public const STARTTLS = 'tls';
    public const IMPLICIT = 'ssl';

    public readonly string $encryption;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        string $encryption,
        public readonly string $username,
        private readonly string $password,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly int $timeoutSeconds = 15,
    ) {
        $this->encryption = match (strtolower(trim($encryption))) {
            'ssl', 'smtps' => self::IMPLICIT,
            'tls', 'starttls' => self::STARTTLS,
            default => self::NONE,
        };
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            trim((string) $config->get('mail.host', '')),
            (int) $config->get('mail.port', 587),
            (string) $config->get('mail.encryption', 'tls'),
            (string) $config->get('mail.username', ''),
            (string) $config->get('mail.password', ''),
            trim((string) $config->get('mail.from_address', '')),
            (string) $config->get('mail.from_name', 'SCHOLARIS'),
            max(1, (int) $config->get('mail.timeout_seconds', 15)),
        );
    }

    /** Le SMTP réel n'est choisi que si MAIL_MAILER=smtp ET si un hôte est renseigné. */
    public static function isSelected(Config $config): bool
    {
        return strtolower((string) $config->get('mail.mailer', 'log')) === 'smtp'
            && trim((string) $config->get('mail.host', '')) !== '';
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, scalar> */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'encryption' => $this->encryption, 'username' => $this->username, 'password' => '***'];
    }
}
