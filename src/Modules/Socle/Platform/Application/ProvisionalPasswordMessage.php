<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

/**
 * Message prêt à partager (copie pour WhatsApp, e-mail, SMS) pour un mot de passe provisoire donné par la plateforme :
 * établissement, identifiant, mot de passe, adresse de connexion ; mention du changement exigé et de la double
 * authentification qui reste demandée. Tenu dans le code, comme AccessShareMessage, et envoyé directement par les passerelles
 * (Integrations) : Messaging refuse par principe une variable secrète et enregistre le texte rendu, or le mot de passe ne
 * doit jamais être stocké en base. Le SMS reste court (un à deux segments).
 */
final class ProvisionalPasswordMessage
{
    public const SMS_MAX = 300;

    /**
     * @param array{first_name: string, establishment_name: string, login: string, password: string, login_url: string, require_change: bool, two_factor: bool} $v
     * @return array{subject: string, text: string, sms: string}
     */
    public static function compose(string $locale, array $v): array
    {
        $en = $locale === 'en';
        $first = trim($v['first_name']);
        $lines = $en
            ? [
                $first === '' ? 'Hello,' : 'Hello ' . $first . ',',
                '',
                'Here is your temporary SCHOLARIS password for ' . $v['establishment_name'] . '.',
                'Login: ' . $v['login'],
                'Password: ' . $v['password'],
                'Sign in on: ' . $v['login_url'],
                '',
                $v['require_change'] ? 'You will be asked to choose a new password when you sign in.' : 'You can change it at any time from your profile.',
            ]
            : [
                $first === '' ? 'Bonjour,' : 'Bonjour ' . $first . ',',
                '',
                'Voici votre mot de passe provisoire SCHOLARIS pour ' . $v['establishment_name'] . '.',
                'Identifiant : ' . $v['login'],
                'Mot de passe : ' . $v['password'],
                'Connexion : ' . $v['login_url'],
                '',
                $v['require_change'] ? 'Un nouveau mot de passe vous sera demandé à la connexion.' : 'Vous pourrez le changer à tout moment depuis votre profil.',
            ];
        if ($v['two_factor']) {
            $lines[] = $en ? 'Your two-step verification code is still required.' : 'Le code de double authentification reste demandé.';
        }
        $lines[] = $en ? 'Never share this password.' : 'Ne communiquez ce mot de passe à personne.';

        return [
            // L'objet ne contient jamais le mot de passe (il peut apparaître dans les journaux d'envoi).
            'subject' => $en ? 'SCHOLARIS: your temporary password for ' . $v['establishment_name'] : 'SCHOLARIS : votre mot de passe provisoire pour ' . $v['establishment_name'],
            'text' => implode("\n", $lines),
            'sms' => self::sms($en, $v),
        ];
    }

    /** @param array{first_name: string, establishment_name: string, login: string, password: string, login_url: string, require_change: bool, two_factor: bool} $v */
    private static function sms(bool $en, array $v): string
    {
        $build = static fn (string $name): string => $en
            ? sprintf('SCHOLARIS %s. Login: %s. Temporary password: %s. Sign in: %s%s', $name, $v['login'], $v['password'], $v['login_url'], $v['two_factor'] ? '. 2-step code still required.' : '.')
            : sprintf('SCHOLARIS %s. Identifiant : %s. Mot de passe provisoire : %s. Connexion : %s%s', $name, $v['login'], $v['password'], $v['login_url'], $v['two_factor'] ? '. Code de double authentification toujours demandé.' : '.');
        $name = $v['establishment_name'];
        $sms = $build($name);
        $excess = mb_strlen($sms) - self::SMS_MAX;
        if ($excess > 0) {
            $sms = $build(rtrim(mb_substr($name, 0, max(10, mb_strlen($name) - $excess - 1))) . '…');
        }

        return $sms;
    }
}
