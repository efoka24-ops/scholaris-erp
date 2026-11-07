<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sms;

use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;

/** Suivi d'état absent : le pilote SMS choisi (journal, HTTP générique) n'en offre pas. */
final class NoSmsStatusLookup implements SmsStatusLookup
{
    public function referencePrefix(): ?string
    {
        return null;
    }

    public function lookup(string $providerReference): ?string
    {
        return null;
    }
}
