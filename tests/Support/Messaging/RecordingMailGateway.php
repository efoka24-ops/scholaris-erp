<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;

/** Passerelle e-mail de test. */
final class RecordingMailGateway implements MailGateway
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): string
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return 'mail-' . count($this->sent);
    }
}
