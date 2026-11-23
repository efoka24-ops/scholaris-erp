<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceGuard;

/** Garde de test (S18) : refuse une attestation dont la classe mentionne « BTS » ; mémorise les émissions vues. */
final class TitleGuard implements IssuanceGuard
{
    /** @var list<string> */
    public static array $seen = [];

    public function refusal(IssuanceContext $context): ?string
    {
        self::$seen[] = $context->via . ':' . $context->type . ':' . ($context->orgUnitId ?? '-');
        if ($context->type === 'attestation_simple' && str_contains((string) ($context->data['class_name'] ?? ''), 'BTS')) {
            return 'Aucun document intitulé BTS ou HND n\'est délivré.';
        }

        return null;
    }
}
