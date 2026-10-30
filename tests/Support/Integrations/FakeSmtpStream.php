<?php

declare(strict_types=1);

namespace Tests\Support\Integrations;

/**
 * Flux PHP « fakesmtp:// » : un socket factice entièrement dans le processus de test. Toute écriture du client est
 * transmise à FakeSmtpScript, dont les réponses sont immédiatement lisibles. Aucun réseau.
 */
final class FakeSmtpStream
{
    public static ?FakeSmtpScript $script = null;

    /** @var resource|null */
    public $context;
    private string $out = '';

    public static function register(FakeSmtpScript $script): void
    {
        self::$script = $script;
        if (!in_array('fakesmtp', stream_get_wrappers(), true)) {
            stream_wrapper_register('fakesmtp', self::class);
        }
    }

    /** @return resource */
    public static function open(): mixed
    {
        $stream = fopen('fakesmtp://server', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux factice indisponible.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $script = self::$script ?? throw new \LogicException('Script SMTP factice absent.');
        $this->out = $script->silentAfterGreeting ? '' : $script->greeting();

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr($this->out, 0, $count);
        $this->out = (string) substr($this->out, strlen($chunk));

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        $this->out .= (self::$script ?? throw new \LogicException('Script SMTP factice absent.'))->receive($data);

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
