<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku;

use RuntimeException;

/**
 * Délai dépassé, réponse 5xx ou 429, coupure réseau : l'échec n'est PAS prouvé (l'argent a peut-être bougé).
 * La transaction locale reste « en attente » ; seule la confirmation par webhook tranche.
 */
final class SungkuUnavailable extends RuntimeException
{
}
