<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Contrat SORTANT : valeurs effectives d'un circuit pour un établissement. Sans réglage, la résolution rend EXACTEMENT les
 * valeurs par défaut déclarées par l'application. Une valeur stockée devenue invalide est ignorée (retour au défaut), jamais
 * appliquée.
 */
interface WorkflowResolver
{
    /** @throws \LogicException circuit non déclaré (erreur de programmation) */
    public function resolve(string $tenantId, string $establishmentId, string $application, string $circuit): ResolvedCircuit;
}
