<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

/** Masquage des coordonnées dans les listes du back-office : « 6•• •• •• 12 », « p•••@domaine ». */
final class Mask
{
    private function __construct()
    {
    }

    public static function address(?string $address): string
    {
        if ($address === null || trim($address) === '') {
            return '—';
        }
        if ($address === 'app') {
            return 'application';
        }

        return str_contains($address, '@') ? self::email($address) : self::phone($address);
    }

    public static function email(?string $email): string
    {
        if ($email === null || !str_contains($email, '@')) {
            return '—';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    public static function phone(?string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '237') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) < 4 ? '—' : substr($digits, 0, 1) . '•• •• •• ' . substr($digits, -2);
    }
}
