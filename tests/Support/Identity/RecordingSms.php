<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;

/** Passerelle SMS de test : conserve les messages envoyés. */
final class RecordingSms implements SmsGateway
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): string
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return 'test-sms-' . count($this->sent);
    }

    /** Dernier code à 6 chiffres envoyé à ce numéro. */
    public function lastCode(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $to && preg_match('/votre code est (\d{6})/', $sms['message'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function messagesTo(string $to): array
    {
        return array_values(array_map(static fn (array $s): string => $s['message'], array_filter($this->sent, static fn (array $s): bool => $s['to'] === $to)));
    }
}
