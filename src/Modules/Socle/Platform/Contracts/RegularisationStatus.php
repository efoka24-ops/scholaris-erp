<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Contracts;

/**
 * Mode régularisation de paiement d'une application d'un client, à consulter pour choisir le message à afficher.
 * `locked` : l'application est entièrement bloquée (ni lecture ni écriture, sauf l'écran de régularisation) ;
 * `read_only` : lecture et export seulement. Fourni par Platform.
 */
interface RegularisationStatus
{
    public const READ_ONLY = 'read_only';
    public const LOCKED = 'locked';

    /** Niveau de blocage de l'application pour ce client, null si elle n'est pas en régularisation de paiement. */
    public function level(string $tenantId, string $appKey): ?string;

    /**
     * Niveau de blocage de tout le client, utilisé par le contrôle central des routes : non null seulement si toutes ses
     * applications non fermées sont bloquées pour impayé (`locked` si toutes le sont à ce niveau, sinon `read_only`).
     */
    public function tenantLevel(string $tenantId): ?string;
}
