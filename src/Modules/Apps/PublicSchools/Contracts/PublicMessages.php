<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Messages aux familles et aux classes des écoles publiques (COM), fourni par PublicSchools\Messages au-dessus de Messaging :
 * respect du consentement et des désinscriptions, sauf les messages essentiels (sécurité : RG-COM-2). Les modèles sont des données
 * (`messaging_templates`), jamais du texte en dur. Aucun message ne porte de donnée sensible (santé, situation particulière).
 */
interface PublicMessages
{
    /** Vrai si le modèle est de catégorie « essentielle » (sécurité de l'enfant) : il part même vers un parent désinscrit. */
    public function isEssential(string $tenantId, string $templateKey): bool;

    /**
     * Envoie le modèle aux garants d'un élève ou d'un enfant.
     *
     * @param array<string, scalar> $variables variables du modèle
     * @return int nombre de destinataires atteints (zéro si aucun garant n'a consenti)
     */
    public function notifyGuardians(string $tenantId, string $establishmentId, string $pupilPersonId, string $templateKey, array $variables = []): int;

    /**
     * Envoie le modèle aux garants des élèves d'une classe ou d'une section.
     *
     * @param array<string, scalar> $variables variables du modèle
     * @return int nombre de destinataires atteints
     */
    public function notifyClass(string $tenantId, string $establishmentId, string $structureId, string $templateKey, array $variables = []): int;

    /**
     * Prévient le directeur de l'école (alerte interne : enfant non récupéré, classe sans enseignant).
     *
     * @param array<string, scalar> $variables variables du modèle
     * @return bool vrai si le directeur a été joint
     */
    public function notifyDirector(string $tenantId, string $establishmentId, string $templateKey, array $variables = []): bool;
}
