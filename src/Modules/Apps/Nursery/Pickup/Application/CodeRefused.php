<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

/** Code de remise refusé : `code_invalid` (essai compté) ou `code_blocked` (essais épuisés). Capturé par la remise, qui journalise. */
final class CodeRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $blockedCodeId)
    {
        parent::__construct($reason);
    }
}
