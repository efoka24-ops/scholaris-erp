<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

use InvalidArgumentException;

/** Modèle ou variables invalides ; le code court sert de motif de blocage d'une livraison. */
final class TemplateException extends InvalidArgumentException
{
    public const VARIABLE_NOT_ALLOWED = 'variable_not_allowed';
    public const MISSING_VARIABLE = 'missing_variable';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
