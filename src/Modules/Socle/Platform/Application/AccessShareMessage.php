<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

/**
 * Message prêt à partager (e-mail, SMS, copie pour WhatsApp) : établissement, identifiant, adresse d'activation ou de
 * mot de passe oublié, code et durée de validité. Tenu dans le code et non en modèle Messaging : la liste blanche des
 * variables de Messaging exclut volontairement un code d'accès, et le texte doit être rendu à l'écran pour la copie.
 */
final class AccessShareMessage
{
    public const SMS_MAX = 480;

    /**
     * @param array{first_name: string, establishment_name: string, login: string, code: string, minutes: int, url: string, login_url: string, reset: bool} $v
     * @return array{subject: string, text: string, sms: string}
     */
    public static function compose(string $locale, array $v): array
    {
        $en = $locale === 'en';
        $kind = $v['reset']
            ? ($en ? 'Password reset code' : 'Code de réinitialisation')
            : ($en ? 'Activation code' : 'Code d\'activation');
        $text = $en
            ? sprintf(
                "Hello %s,\n\nYour SCHOLARIS access for %s is ready.\nLogin: %s\n%s: %s (valid for %d minutes)\n\n1. Open %s and enter your login, then the code.\n2. Choose your password, then sign in on %s.\n\nIf the code has expired, request a new one on the same page. Never share this code.",
                $v['first_name'],
                $v['establishment_name'],
                $v['login'],
                $kind,
                $v['code'],
                $v['minutes'],
                $v['url'],
                $v['login_url'],
            )
            : sprintf(
                "Bonjour %s,\n\nVotre accès SCHOLARIS pour %s est prêt.\nIdentifiant : %s\n%s : %s (valable %d minutes)\n\n1. Ouvrez %s et saisissez votre identifiant, puis le code.\n2. Choisissez votre mot de passe, puis connectez-vous sur %s.\n\nSi le code a expiré, demandez-en un nouveau sur la même page. Ne communiquez ce code à personne.",
                $v['first_name'],
                $v['establishment_name'],
                $v['login'],
                $kind,
                $v['code'],
                $v['minutes'],
                $v['url'],
                $v['login_url'],
            );

        return [
            'subject' => $en ? 'SCHOLARIS: your access to ' . $v['establishment_name'] : 'SCHOLARIS : votre accès à ' . $v['establishment_name'],
            'text' => $text,
            'sms' => self::sms($en, $v),
        ];
    }

    /** @param array{first_name: string, establishment_name: string, login: string, code: string, minutes: int, url: string, login_url: string, reset: bool} $v */
    private static function sms(bool $en, array $v): string
    {
        $build = static fn (string $name): string => $en
            ? sprintf('SCHOLARIS %s: login %s, code %s (valid %d min). Open %s, enter your login then the code. Expired? Request a new one there. Never share it.', $name, $v['login'], $v['code'], $v['minutes'], $v['url'])
            : sprintf('SCHOLARIS %s : identifiant %s, code %s (valable %d min). Ouvrez %s, saisissez l\'identifiant puis le code. Expiré ? Redemandez-le sur la même page. Ne le communiquez à personne.', $name, $v['login'], $v['code'], $v['minutes'], $v['url']);
        $name = $v['establishment_name'];
        $sms = $build($name);
        $excess = mb_strlen($sms) - self::SMS_MAX;
        if ($excess > 0) {
            $sms = $build(rtrim(mb_substr($name, 0, max(10, mb_strlen($name) - $excess - 1))) . '…');
        }

        return mb_substr($sms, 0, self::SMS_MAX);
    }
}
