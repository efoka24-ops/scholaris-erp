<?php

declare(strict_types=1);

namespace Scholaris\Auth;

/**
 * TOTP (RFC 6238), sur HMAC-SHA1 (RFC 4226).
 *
 * Implementation minimale, sans dependance : un compteur derive de l'heure
 * courante, hache avec le secret partage, tronque en un code a six chiffres.
 * C'est exactement l'algorithme qu'utilisent Google Authenticator et les
 * applications equivalentes, ce qui permet d'enroler le secret genere ici
 * dans n'importe laquelle d'entre elles via son URL otpauth://.
 */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Une periode avant et apres : tolere une petite derive d'horloge. */
    private const WINDOW = 1;

    /** Secret aleatoire de 20 octets, encode en Base32 (format standard des apps TOTP). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * URL otpauth:// a coder en QR par une application externe. Le serveur ne
     * genere aucune image : le libelle et l'emetteur suffisent pour que
     * l'utilisateur reconnaisse le compte dans son application.
     */
    public static function provisioningUri(string $secret, string $accountLabel, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /** Verifie un code a six chiffres, avec une tolerance d'une periode de chaque cote. */
    public static function verify(string $secret, string $code): bool
    {
        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);

        for ($drift = -self::WINDOW; $drift <= self::WINDOW; $drift++) {
            if (hash_equals(self::codeAt($secret, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N*', 0, $counter); // 64 bits, poids fort a zero.
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);

        // Troncature dynamique (RFC 4226 §5.3) : les 4 derniers bits du hachage
        // designent le decalage d'ou lire les 4 octets porteurs du code.
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';

        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos($alphabet, $char);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                continue;
            }

            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }
}
