<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Dépôt de pièces jointes du socle pour les applications : même contrôle que le dossier documentaire (type réel lu dans le
 * contenu, PDF/JPG/PNG, 5 Mo au plus, analyse FileScanner) et même stockage privé hors racine web, rangé par client puis par
 * domaine (`<client>/<domaine>/<propriétaire>/<ulid>.<ext>`). Le nom du fichier n'est jamais pris du client.
 */
interface AttachmentStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Contrôle puis enregistre une pièce.
     *
     * @param string $area domaine applicatif (`higher-grades`…), minuscules et tirets
     * @param string $ownerId objet propriétaire (identifiant de la demande, de la personne…)
     *
     * @throws \InvalidArgumentException pièce refusée (vide, trop lourde, type non accepté, contenu actif) ; message lisible
     */
    public function store(string $tenantId, string $area, string $ownerId, string $content): StoredAttachment;

    /** Contenu d'une pièce du client, ou null si elle n'existe pas ou appartient à un autre client. */
    public function read(string $tenantId, string $path): ?string;

    public function delete(string $tenantId, string $path): void;
}
