<?php

declare(strict_types=1);

namespace Tests\Support\Integrations;

/**
 * Faux serveur SMTP déterministe (machine à états, sans réseau) : reçoit les octets écrits par le client et fabrique
 * les réponses. Sert de cerveau au flux factice FakeSmtpStream et au script de serveur de boucle locale.
 */
final class FakeSmtpScript
{
    /** @var list<string> lignes de commande reçues (hors contenu du message) */
    public array $commands = [];
    public ?string $data = null;
    /** @var list<string> */
    public array $capabilities = ['PIPELINING', '8BITMIME', 'AUTH PLAIN LOGIN'];
    public string $user = 'noreply@example.test';
    public string $password = 'p@ss-w0rd';
    public int $rcptCode = 250;
    public int $dataCode = 250;
    public bool $silentAfterGreeting = false;

    private string $incoming = '';
    private bool $inData = false;
    private string $dataBuffer = '';
    private ?string $authStep = null;
    public bool $authenticated = false;

    public function greeting(): string
    {
        return "220 fake.smtp.test ESMTP\r\n";
    }

    /** @return string réponses à renvoyer au client */
    public function receive(string $bytes): string
    {
        $this->incoming .= $bytes;
        $out = '';

        while (true) {
            if ($this->inData) {
                $end = strpos($this->incoming, "\r\n.\r\n");
                if ($end === false) {
                    return $out;
                }
                $this->data = $this->dataBuffer . substr($this->incoming, 0, $end);
                $this->incoming = substr($this->incoming, $end + 5);
                $this->inData = false;
                $out .= $this->dataCode === 250 ? "250 2.0.0 queued\r\n" : $this->dataCode . " refused\r\n";
                continue;
            }
            $eol = strpos($this->incoming, "\r\n");
            if ($eol === false) {
                return $out;
            }
            $line = substr($this->incoming, 0, $eol);
            $this->incoming = substr($this->incoming, $eol + 2);
            $out .= $this->answer($line);
        }
    }

    private function answer(string $line): string
    {
        if ($this->authStep !== null) {
            return $this->authLine($line);
        }
        $this->commands[] = str_starts_with($line, 'AUTH PLAIN ') ? 'AUTH PLAIN <redacted>' : $line;
        $verb = strtoupper(strtok($line, ' ') ?: '');

        return match ($verb) {
            'EHLO' => $this->ehlo(),
            'STARTTLS' => "220 ready to start TLS\r\n",
            'AUTH' => $this->auth($line),
            'MAIL' => "250 sender ok\r\n",
            'RCPT' => $this->rcptCode === 250 ? "250 recipient ok\r\n" : $this->rcptCode . " mailbox unavailable\r\n",
            'DATA' => $this->startData(),
            'QUIT' => "221 bye\r\n",
            default => "502 command not implemented\r\n",
        };
    }

    private function ehlo(): string
    {
        $lines = ['fake.smtp.test', ...$this->capabilities];
        $out = '';
        foreach ($lines as $i => $text) {
            $out .= '250' . ($i === array_key_last($lines) ? ' ' : '-') . $text . "\r\n";
        }

        return $out;
    }

    private function auth(string $line): string
    {
        if (str_starts_with($line, 'AUTH PLAIN ')) {
            $this->authenticated = base64_decode(substr($line, 11), true) === "\0" . $this->user . "\0" . $this->password;

            return $this->authenticated ? "235 authenticated\r\n" : "535 bad credentials\r\n";
        }
        if ($line === 'AUTH LOGIN') {
            $this->authStep = 'user';

            return '334 ' . base64_encode('Username:') . "\r\n";
        }

        return "504 mechanism not supported\r\n";
    }

    private function authLine(string $line): string
    {
        $value = (string) base64_decode($line, true);
        if ($this->authStep === 'user') {
            $this->commands[] = 'AUTH LOGIN <user>';
            $this->authStep = $value === $this->user ? 'password' : 'reject';

            return '334 ' . base64_encode('Password:') . "\r\n";
        }
        $ok = $this->authStep === 'password' && $value === $this->password;
        $this->authStep = null;
        $this->authenticated = $ok;

        return $ok ? "235 authenticated\r\n" : "535 bad credentials\r\n";
    }

    private function startData(): string
    {
        $this->inData = true;

        return "354 go ahead\r\n";
    }
}
