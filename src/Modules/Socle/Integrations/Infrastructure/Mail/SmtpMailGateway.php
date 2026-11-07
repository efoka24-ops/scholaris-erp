<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Mail;

use Closure;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Throwable;

/**
 * Passerelle e-mail SMTP minimale sur sockets (symfony/mailer n'est pas installé) : STARTTLS ou SSL implicite,
 * authentification PLAIN puis LOGIN, délai maximal, corps UTF-8 encodé en base64.
 *
 * Sécurité : jamais de repli en clair si le chiffrement est demandé ; l'authentification en clair est refusée hors
 * boucle locale ; le mot de passe n'apparaît ni dans les journaux ni dans les exceptions (les échanges SMTP ne sont pas
 * journalisés) ; destinataire et sujet sont contrôlés contre l'injection d'en-têtes.
 */
final class SmtpMailGateway implements MailGateway
{
    /** @var Closure(string, int, int): resource */
    private readonly Closure $connector;

    /** @param (callable(string, int, int): resource)|null $connector ouvre le flux (hôte, port, délai) ; substituable dans les tests */
    public function __construct(
        private readonly SmtpSettings $settings,
        private readonly ?LoggerInterface $logger = null,
        ?callable $connector = null,
        private readonly string $clientName = 'scholaris.local',
    ) {
        $this->connector = Closure::fromCallable($connector ?? $this->openSocket(...));
    }

    public function send(string $to, string $subject, string $body): string
    {
        $recipient = trim($to);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $recipient) === 1) {
            throw new SmtpException('Adresse du destinataire invalide.');
        }
        $from = $this->settings->fromAddress;
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new SmtpException('Adresse d\'expédition invalide (MAIL_FROM_ADDRESS).');
        }
        if ($this->settings->host === '') {
            throw new SmtpException('Serveur SMTP non configuré (MAIL_HOST).');
        }
        if ($this->settings->username !== '' && $this->settings->encryption === SmtpSettings::NONE && !self::isLoopback($this->settings->host)) {
            throw new SmtpException('Authentification SMTP refusée sans chiffrement (MAIL_ENCRYPTION).');
        }

        $messageId = '<' . strtolower((string) new Ulid()) . '@' . self::domainOf($from) . '>';
        $data = $this->compose($recipient, $subject, $body, $messageId);

        $stream = null;
        try {
            $stream = ($this->connector)($this->settings->host, $this->settings->port, $this->settings->timeoutSeconds);
            $this->dialogue($stream, $from, $recipient, $data);
        } catch (SmtpException $e) {
            $this->logger?->warning('Échec SMTP', ['host' => $this->settings->host, 'smtp_code' => $e->smtpCode, 'reason' => $e->getMessage()]);

            throw $e;
        } catch (Throwable $e) {
            $this->logger?->warning('Échec SMTP', ['host' => $this->settings->host, 'error' => $e::class]);

            throw new SmtpException('Serveur SMTP injoignable.', 0);
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        $this->logger?->info('E-mail envoyé par SMTP', ['host' => $this->settings->host, 'ref' => $messageId]);

        return $messageId;
    }

    /** @param resource $stream */
    private function dialogue($stream, string $from, string $recipient, string $data): void
    {
        $this->expect($this->read($stream), [220], 'accueil');
        $capabilities = $this->ehlo($stream);

        if ($this->settings->encryption === SmtpSettings::STARTTLS) {
            if (!in_array('STARTTLS', $capabilities, true)) {
                throw new SmtpException('Le serveur n\'offre pas STARTTLS : envoi refusé (pas de repli en clair).');
            }
            $this->command($stream, 'STARTTLS', [220]);
            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new SmtpException('Négociation TLS échouée.');
            }
            $capabilities = $this->ehlo($stream);
        }

        if ($this->settings->username !== '') {
            $this->authenticate($stream, $capabilities);
        }

        $this->command($stream, 'MAIL FROM:<' . $from . '>', [250]);
        $this->command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        $this->command($stream, 'DATA', [354]);
        $this->write($stream, $data . "\r\n.\r\n");
        $this->expect($this->read($stream), [250], 'message');
        try {
            $this->command($stream, 'QUIT', [221]);
        } catch (SmtpException) {
            // Le message est accepté : une fermeture mal formée n'invalide pas l'envoi.
        }
    }

    /**
     * @param resource $stream
     * @return list<string> lignes de capacités annoncées, en majuscules
     */
    private function ehlo($stream): array
    {
        $reply = $this->command($stream, 'EHLO ' . $this->clientName, [250]);

        return array_map('strtoupper', array_slice($reply['lines'], 1));
    }

    /**
     * @param resource $stream
     * @param list<string> $capabilities
     */
    private function authenticate($stream, array $capabilities): void
    {
        $mechanisms = [];
        foreach ($capabilities as $line) {
            if (str_starts_with($line, 'AUTH')) {
                $mechanisms = array_merge($mechanisms, preg_split('/[\s=]+/', $line) ?: []);
            }
        }
        $user = $this->settings->username;
        $password = $this->settings->password();

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command($stream, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $password), [235], 'authentification');

            return;
        }
        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command($stream, 'AUTH LOGIN', [334]);
            $this->command($stream, base64_encode($user), [334], 'authentification');
            $this->command($stream, base64_encode($password), [235], 'authentification');

            return;
        }

        throw new SmtpException('Aucun mécanisme d\'authentification pris en charge (PLAIN, LOGIN).');
    }

    /**
     * @param resource $stream
     * @param list<int> $accepted
     * @return array{code: int, lines: list<string>}
     */
    private function command($stream, string $line, array $accepted, ?string $step = null): array
    {
        $this->write($stream, $line . "\r\n");

        return $this->expect($this->read($stream), $accepted, $step ?? (string) strtok($line, ' '));
    }

    /**
     * @param resource $stream
     * @return array{code: int, lines: list<string>}
     */
    private function read($stream): array
    {
        $lines = [];
        do {
            $raw = fgets($stream, 1024);
            if ($raw === false) {
                $meta = stream_get_meta_data($stream);
                throw new SmtpException($meta['timed_out'] ? 'Délai dépassé en attendant le serveur SMTP.' : 'Connexion SMTP interrompue.');
            }
            if (preg_match('/^(\d{3})([ -])(.*)$/', rtrim($raw, "\r\n"), $m) !== 1) {
                throw new SmtpException('Réponse SMTP illisible.');
            }
            $lines[] = $m[3];
        } while ($m[2] === '-');

        return ['code' => (int) $m[1], 'lines' => $lines];
    }

    /**
     * @param array{code: int, lines: list<string>} $reply
     * @param list<int> $accepted
     * @return array{code: int, lines: list<string>}
     */
    private function expect(array $reply, array $accepted, string $step): array
    {
        if (!in_array($reply['code'], $accepted, true)) {
            // Le texte du serveur n'est pas repris : il pourrait contenir des données sensibles.
            throw new SmtpException(sprintf('Le serveur SMTP a refusé l\'étape « %s » (code %d).', $step, $reply['code']), $reply['code']);
        }

        return $reply;
    }

    /** @param resource $stream */
    private function write($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new SmtpException('Écriture SMTP impossible.');
            }
            $written += $chunk;
        }
    }

    private function compose(string $recipient, string $subject, string $body, string $messageId): string
    {
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));
        $fromName = trim((string) preg_replace('/[\r\n"<>]+/', ' ', $this->settings->fromName));
        $from = $fromName === ''
            ? $this->settings->fromAddress
            : self::encodeWord($fromName) . ' <' . $this->settings->fromAddress . '>';

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $from,
            'To: <' . $recipient . '>',
            'Subject: ' . self::encodeWord($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];
        // Texte brut (messageries anciennes, lecteurs d'écran) et version HTML mise en page (EmailLayout).
        $boundary = 'scholaris-' . bin2hex(random_bytes(12));
        $html = EmailLayout::render($subject, $body, $fromName);
        $part = static fn (string $type, string $content): string => '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $body) . $part('text/html', $html) . '--' . $boundary . '--';
    }

    private static function encodeWord(string $text): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $text) === 1 ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? 'localhost' : substr($address, $at + 1);
    }

    private static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    /** @return resource */
    private function openSocket(string $host, int $port, int $timeout)
    {
        $scheme = $this->settings->encryption === SmtpSettings::IMPLICIT ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $stream = @stream_socket_client(sprintf('%s://%s:%d', $scheme, $host, $port), $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new SmtpException('Connexion au serveur SMTP impossible.');
        }
        stream_set_timeout($stream, $timeout);

        return $stream;
    }
}
