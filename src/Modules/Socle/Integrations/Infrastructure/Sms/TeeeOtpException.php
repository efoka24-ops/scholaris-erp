<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use RuntimeException;

/**
 * Échec d'un appel à TeeeOTP. Le message est écrit ici (jamais repris de la réponse du service) : il ne contient ni clé API,
 * ni numéro, ni texte de SMS. `errorCode` reprend le code TeeeOTP (QUOTA_EXCEEDED, INVALID_PHONE, UNREACHABLE…).
 */
final class TeeeOtpException extends RuntimeException
{
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
    public const INVALID_PHONE = 'INVALID_PHONE';
    public const INVALID_MESSAGE = 'INVALID_MESSAGE';
    public const UNREACHABLE = 'UNREACHABLE';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const UNEXPECTED = 'UNEXPECTED';

    private const MESSAGES = [
        self::QUOTA_EXCEEDED => 'Quota de SMS TeeeOTP épuisé.',
        self::TOO_MANY_REQUESTS => 'Trop de demandes envoyées à TeeeOTP : réessayer plus tard.',
        self::INVALID_PHONE => 'Numéro de téléphone refusé (mobile camerounais attendu).',
        self::INVALID_MESSAGE => 'Message SMS refusé par TeeeOTP.',
        self::UNREACHABLE => 'Service TeeeOTP injoignable.',
        self::UNAUTHORIZED => 'Clé API TeeeOTP refusée.',
        self::NOT_CONFIGURED => 'TeeeOTP non configuré (TEEEOTP_BASE_URL, TEEEOTP_API_KEY).',
        self::UNEXPECTED => 'Réponse inattendue de TeeeOTP.',
    ];

    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? ('Erreur TeeeOTP ' . preg_replace('/[^A-Z0-9_]/', '', strtoupper($errorCode)) . '.'));
    }

    /** Une nouvelle tentative plus tard peut réussir (le numéro, le message ou la clé ne sont pas en cause). */
    public function isTemporary(): bool
    {
        return in_array($this->errorCode, [self::TOO_MANY_REQUESTS, self::UNREACHABLE, self::QUOTA_EXCEEDED], true) || $this->httpStatus >= 500;
    }
}
