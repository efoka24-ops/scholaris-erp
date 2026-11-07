<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Ecriture de preréglages de paramètres par une application, au niveau de l'établissement (ex. attendance.mode à l'ouverture de
 * l'application primaire). Jamais d'écrasement : une valeur déjà posée à ce niveau est conservée. Chaque pose est tracée dans l'audit.
 */
interface SettingsWriter
{
    /** @return bool vrai si la valeur a été posée, faux si l'établissement avait déjà une valeur à ce niveau */
    public function presetIfAbsent(string $tenantId, string $establishmentId, string $key, mixed $value, string $actorId): bool;
}
