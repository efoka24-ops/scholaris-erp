<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/** Document officiel émis : numéro unique, jeton de vérification (QR), statut. */
final class IssuedDocument
{
    public const VALID = 'valid';
    public const REVOKED = 'revoked';

    public function __construct(
        public readonly string $id,
        public readonly string $number,
        public readonly string $verificationToken,
        public readonly string $status,
        public readonly string $type,
        public readonly string $subjectPersonId,
    ) {
    }
}
