<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Identity\Domain\User;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Throwable;

/** Messages de sécurité envoyés par SMS et e-mail (codes, alertes). Les alertes ne bloquent jamais l'action qui les déclenche. */
final class SecurityNotifier
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly MailGateway $mail,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Envoie le code par le canal choisi. Une panne d'envoi remonte : la personne doit pouvoir réessayer. */
    public function sendCode(User $user, string $channel, string $code, int $ttlSeconds): void
    {
        $minutes = intdiv($ttlSeconds, 60);
        $text = sprintf('SCHOLARIS : votre code est %s. Il expire dans %d minutes. Ne le communiquez à personne.', $code, $minutes);
        if ($channel === 'email' && $user->email !== null) {
            $this->mail->send($user->email, 'Votre code SCHOLARIS', $text);

            return;
        }
        if ($user->phone !== null) {
            $this->sms->send($user->phone, $text);
        }
    }

    public function newDevice(User $user, string $deviceName, ?string $location, string $when): void
    {
        $where = $location !== null ? ' à ' . $location : '';
        $this->alert(
            $user,
            'Nouvel appareil détecté',
            sprintf(
                'SCHOLARIS : connexion depuis un nouvel appareil (%s%s) le %s. Si ce n\'est pas vous, utilisez « Ce n\'est pas moi » dans l\'application.',
                $deviceName,
                $where,
                $when,
            ),
        );
    }

    public function lockedOut(User $user, string $until): void
    {
        $this->alert(
            $user,
            'Compte verrouillé temporairement',
            sprintf('SCHOLARIS : plusieurs tentatives de connexion ont échoué. Votre compte est verrouillé jusqu\'à %s. Si ce n\'est pas vous, changez votre mot de passe.', $until),
        );
    }

    public function accountBlocked(User $user): void
    {
        $this->alert(
            $user,
            'Compte bloqué',
            'SCHOLARIS : votre compte a été bloqué et tous vos appareils déconnectés. Réinitialisez votre mot de passe pour le rouvrir.',
        );
    }

    public function passwordChanged(User $user): void
    {
        $this->alert($user, 'Mot de passe modifié', 'SCHOLARIS : votre mot de passe vient d\'être modifié. Si ce n\'est pas vous, contactez votre établissement.');
    }

    private function alert(User $user, string $subject, string $text): void
    {
        try {
            if ($user->phone !== null) {
                $this->sms->send($user->phone, $text);
            }
            if ($user->email !== null) {
                $this->mail->send($user->email, $subject, $text);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Alerte de sécurité non envoyée', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
