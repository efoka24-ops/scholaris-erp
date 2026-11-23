<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;

/**
 * Accès facultatif aux messages aux familles (COM, fournis par PublicSchools\Messages) : tant que ce module n'est pas branché,
 * les notifications (NOT-01, relance d'abandon) sont simplement omises. Un échec d'envoi n'annule jamais l'opération métier.
 */
final class GuardianNotifier
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function gateway(): ?PublicMessages
    {
        if (!$this->container->has(PublicMessages::class)) {
            return null;
        }
        $gateway = $this->container->get(PublicMessages::class);

        return $gateway instanceof PublicMessages ? $gateway : null;
    }
}
