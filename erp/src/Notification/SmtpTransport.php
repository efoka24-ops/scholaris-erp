<?php

declare(strict_types=1);

namespace Scholaris\Notification;

use RuntimeException;

/**
 * Client SMTP, en sockets, sans dependance.
 *
 * La fonction mail() de PHP remet le courrier au serveur local ; sur un
 * hebergement mutualise, ce courrier part avec une adresse d'expedition que
 * l'on ne maitrise pas et finit regulierement en indesirable. S'authentifier
 * aupres du serveur de messagerie du domaine permet d'expedier reellement
 * depuis noreply@, ce qui change tout pour la delivrabilite.
 *
 * Deux modes de chiffrement : implicite (port 465, la session est chiffree des
 * l'ouverture) ou STARTTLS (port 587, la session commence en clair puis passe
 * en chiffre). Aucun envoi n'est fait en clair : si STARTTLS echoue, la
 * transmission est abandonnee plutot que de laisser partir un mot de passe
 * provisoire sur un canal ouvert.
 */
final class SmtpTransport
{
    private string $host;

    private int $port;

    private string $username;

    private string $password;

    /** 'ssl' (implicite), 'tls' (STARTTLS) ou 'none'. */
    private string $encryption;

    private int $timeout;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption = 'ssl',
        int $timeout = 15
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = $encryption;
        $this->timeout = $timeout;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws RuntimeException si le serveur refuse une etape
     */
    public function send(string $from, string $fromName, string $to, string $subject, string $body): void
    {
        $this->connect();

        try {
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<'.$from.'>', [250]);
            $this->command('RCPT TO:<'.$to.'>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($this->message($from, $fromName, $to, $subject, $body));
            $this->command('.', [250]);

            // QUIT peut echouer sans consequence : le courrier est deja
            // accepte a ce stade.
            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException $e) {
                // Rien a faire.
            }
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $endpoint = ($this->encryption === 'ssl' ? 'ssl://' : '').$this->host.':'.$this->port;

        $socket = @stream_socket_client(
            $endpoint,
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new RuntimeException(
                'Connexion au serveur de messagerie impossible ('.$this->host.':'.$this->port.') : '
                .($errorMessage !== '' ? $errorMessage : 'delai depasse')
            );
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->expect([220]);
    }

    private function handshake(): void
    {
        $this->command('EHLO '.$this->clientName(), [250]);

        if ($this->encryption !== 'tls') {
            return;
        }

        $this->command('STARTTLS', [220]);

        $upgraded = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($upgraded !== true) {
            // Poursuivre en clair ferait transiter le mot de passe du compte
            // et celui du directeur sur un canal ouvert.
            throw new RuntimeException('Le passage en TLS a echoue : envoi abandonne.');
        }

        // Apres STARTTLS, la session repart de zero : il faut se re-presenter.
        $this->command('EHLO '.$this->clientName(), [250]);
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->username), [334]);
        $this->command(base64_encode($this->password), [235]);
    }

    private function message(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $headers = [
            'Date: '.date('r'),
            'From: '.$this->encodeHeader($fromName).' <'.$from.'>',
            'To: <'.$to.'>',
            'Subject: '.$this->encodeHeader($subject),
            'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$this->domainOf($from).'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        // Base64 met le corps a l'abri des lignes trop longues et des accents,
        // que certains relais mutilent.
        $encoded = chunk_split(base64_encode($body), 76, "\r\n");

        return implode("\r\n", $headers)."\r\n\r\n".$encoded;
    }

    /** @param  list<int>  $expected */
    private function command(string $command, array $expected): string
    {
        $this->write($command);

        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('Session SMTP fermee.');
        }

        if (@fwrite($this->socket, $data."\r\n") === false) {
            throw new RuntimeException('Ecriture impossible vers le serveur de messagerie.');
        }
    }

    /**
     * Lit une reponse, en suivant les lignes de continuation.
     *
     * @param  list<int>  $expected
     */
    private function expect(array $expected): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('Session SMTP fermee.');
        }

        $response = '';

        while (true) {
            $line = @fgets($this->socket, 1024);

            if ($line === false) {
                throw new RuntimeException('Le serveur de messagerie n a pas repondu.');
            }

            $response .= $line;

            // Une reponse s'acheve sur "250 texte" ; "250-texte" annonce une
            // suite.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (! in_array($code, $expected, true)) {
            throw new RuntimeException('Le serveur de messagerie a repondu : '.trim($response));
        }

        return $response;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Nom annonce dans EHLO.
     *
     * Certains serveurs refusent un nom qui n'est pas un domaine : le nom
     * d'hote du serveur web convient, faute de mieux.
     */
    private function clientName(): string
    {
        $name = $_SERVER['SERVER_NAME'] ?? '';

        if (! is_string($name) || $name === '') {
            $name = gethostname();
        }

        return is_string($name) && $name !== '' ? $name : 'localhost';
    }

    private function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? 'localhost' : substr($address, $at + 1);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }
}
