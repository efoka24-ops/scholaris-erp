<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/** Lance une commande de la console (`artisan <arguments>`) dans un processus indépendant, en arrière-plan. */
interface WorkerLauncher
{
    /**
     * @param list<string> $arguments nom de la commande puis ses options
     * @return bool faux si l'hébergement ne permet pas de lancer un processus (fonctions désactivées)
     */
    public function launch(array $arguments): bool;
}
