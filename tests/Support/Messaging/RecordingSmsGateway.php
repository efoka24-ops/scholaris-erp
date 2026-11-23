<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;

/** Passerelle SMS de test : conserve les messages envoyés. */
final class RecordingSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $message): string
    {
        if ($this->fail) {
            throw new \RuntimeException('panne fournisseur');
        }
        $this->sent[] = ['to' => $to, 'message' => $message];

        return 'sms-' . count($this->sent);
    }
}
