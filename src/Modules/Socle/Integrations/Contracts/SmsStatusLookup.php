<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * Relecture de l'état d'un SMS déjà confié au fournisseur (suivi d'acheminement). Sans fournisseur capable de suivre
 * l'état (journal, passerelle HTTP générique), `lookup` répond toujours null.
 */
interface SmsStatusLookup
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    /** Préfixe des références suivies par ce fournisseur (ex. « teeeotp- »), ou null s'il n'offre pas de suivi. */
    public function referencePrefix(): ?string;

    /**
     * @param string $providerReference référence renvoyée par SmsGateway::send
     * @return string|null PENDING, SENT, DELIVERED ou FAILED ; null si la référence n'est pas suivie par ce fournisseur
     */
    public function lookup(string $providerReference): ?string;
}
