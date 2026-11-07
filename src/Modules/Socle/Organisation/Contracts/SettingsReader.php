<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Lecture des paramètres tels qu'ils s'appliquent (héritage plateforme, client, établissement). */
interface SettingsReader
{
    /** Valeur effective de la clé pour l'établissement (ou pour le client si $establishmentId est nul), sinon $default. */
    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed;
}
