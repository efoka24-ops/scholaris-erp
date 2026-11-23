<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Active des modules du socle pour un établissement (ajout à `modules_enabled`, jamais de retrait). Fourni par Organisation. */
interface ModuleActivator
{
    /**
     * @param list<string> $modules clés de modules du socle ; les clés inconnues sont ignorées
     * @throws \Scholaris\Core\Http\HttpException 404 si l'établissement n'appartient pas au client
     */
    public function enable(string $tenantId, string $establishmentId, array $modules): void;
}
