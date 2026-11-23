<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Psr\Log\AbstractLogger;
use Stringable;

/** Journal de test : conserve tout ce qui est écrit. */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    public function dump(): string
    {
        return implode("\n", $this->lines);
    }
}
