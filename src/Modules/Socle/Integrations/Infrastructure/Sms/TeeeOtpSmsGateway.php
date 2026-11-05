<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;

/**
 * Passerelle SMS TeeeOTP (SMS_DRIVER=teeeotp). La référence renvoyée est « teeeotp-<identifiant TeeeOTP> » : elle est
 * mémorisée avec la livraison puis relue par `sms:sync-status` pour suivre l'acheminement.
 *
 * Numéro normalisé en +2376XXXXXXXX (mobiles camerounais seulement, comme TeeeOTP), message tronqué proprement à 480
 * caractères. Ni la clé, ni le numéro, ni le texte ne sont journalisés.
 */
final class TeeeOtpSmsGateway implements SmsGateway, SmsStatusLookup
{
    public const REFERENCE_PREFIX = 'teeeotp-';
    public const MAX_LENGTH = 480;

    public function __construct(private readonly TeeeOtpClient $client, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function send(string $to, string $message): string
    {
        $phone = self::normalizePhone($to);
        if ($phone === null) {
            throw new TeeeOtpException(TeeeOtpException::INVALID_PHONE);
        }
        $text = self::truncate($message);
        if ($text === '') {
            throw new TeeeOtpException(TeeeOtpException::INVALID_MESSAGE);
        }

        try {
            $id = $this->client->sendSms($phone, $text);
        } catch (TeeeOtpException $e) {
            $this->logger?->warning('Échec SMS TeeeOTP', ['code' => $e->errorCode, 'http_status' => $e->httpStatus]);

            throw $e;
        }

        return self::REFERENCE_PREFIX . $id;
    }

    public function referencePrefix(): string
    {
        return self::REFERENCE_PREFIX;
    }

    public function lookup(string $providerReference): ?string
    {
        if (!str_starts_with($providerReference, self::REFERENCE_PREFIX) || strlen($providerReference) === strlen(self::REFERENCE_PREFIX)) {
            return null;
        }

        try {
            $status = $this->client->smsStatus(substr($providerReference, strlen(self::REFERENCE_PREFIX)));
        } catch (TeeeOtpException $e) {
            $this->logger?->warning('Suivi SMS TeeeOTP impossible', ['code' => $e->errorCode, 'http_status' => $e->httpStatus]);

            throw $e;
        }

        return match ($status) {
            'delivered' => SmsStatusLookup::DELIVERED,
            'failed', 'expired' => SmsStatusLookup::FAILED,
            'sent' => SmsStatusLookup::SENT,
            default => SmsStatusLookup::PENDING,
        };
    }

    /** Mobile camerounais « 6XXXXXXXX », « 2376XXXXXXXX », « +237 6XX XX XX XX » ou « 00237… » → « +2376XXXXXXXX » ; null sinon. */
    public static function normalizePhone(string $raw): ?string
    {
        $clean = (string) preg_replace('/[\s.\-()]/', '', trim($raw));
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }
        if (preg_match('/^\+?237(6[0-9]{8})$/', $clean, $m) === 1 || preg_match('/^(6[0-9]{8})$/', $clean, $m) === 1) {
            return '+237' . $m[1];
        }

        return null;
    }

    /** Au plus 480 caractères : coupe à la dernière espace proche de la limite et ajoute « … ». */
    public static function truncate(string $message): string
    {
        $message = trim($message);
        if (mb_strlen($message) <= self::MAX_LENGTH) {
            return $message;
        }
        $cut = mb_substr($message, 0, self::MAX_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= self::MAX_LENGTH - 60) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut) . '…';
    }
}
