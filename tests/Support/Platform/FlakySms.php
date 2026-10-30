<?php

declare(strict_types=1);

namespace Tests\Support\Platform;

use RuntimeException;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;

/** Passerelle SMS de test qui peut tomber en panne (fournisseur indisponible). */
final class FlakySms implements SmsGateway
{
    public bool $failing = false;

    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): string
    {
        if ($this->failing) {
            throw new RuntimeException('Fournisseur SMS indisponible');
        }
        $this->sent[] = ['to' => $to, 'message' => $message];

        return 'sms-' . count($this->sent);
    }

    /** Dernier code à 6 chiffres envoyé à ce numéro. */
    public function lastCode(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $to && preg_match('/code (\d{6})/', $sms['message'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
