<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Contracts;

/**
 * Droit d'usage d'une application par un client, consulté par les applications (src/Modules/Apps/*) avant toute écriture.
 * Suspendue (impayé au-delà du délai de grâce ou décision de la plateforme) : lecture et export restent permis,
 * les écritures sont refusées. Fermée ou jamais ouverte : ni lecture ni écriture. Fourni par Platform.
 */
interface ApplicationAccess
{
    public const TRIAL = 'trial';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const SUSPENDED = 'suspended';
    public const CLOSED = 'closed';

    /** État du droit d'usage (constantes ci-dessus), null si l'application n'a jamais été ouverte à ce client. */
    public function state(string $tenantId, string $appKey): ?string;

    /** Vrai en essai, actif ou impayé dans le délai de grâce. */
    public function allowsWrites(string $tenantId, string $appKey): bool;

    /** Vrai tant que l'application n'est pas fermée (suspendue comprise : données consultables et exportables). */
    public function allowsReads(string $tenantId, string $appKey): bool;
}
