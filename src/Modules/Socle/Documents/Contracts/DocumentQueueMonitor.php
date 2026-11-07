<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * File des lots de documents de TOUS les clients, en lecture seule, pour la santé de la plateforme (C-112) : comptes
 * seulement, aucun document ni contenu.
 */
interface DocumentQueueMonitor
{
    /**
     * @param string $since ISO 8601 : les échecs sont comptés depuis cette date
     * @return array{pending: int, running: int, failed: int, oldest_pending_at: ?string} en attente = queued
     */
    public function queue(string $since): array;
}
