<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;

/** Passerelle e-mail de test : conserve les messages envoyés. */
final class RecordingMail implements MailGateway
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): string
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return 'test-mail-' . count($this->sent);
    }

    public function lastCode(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $mail) {
            if ($mail['to'] === $to && preg_match('/votre code est (\d{6})/', $mail['body'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
