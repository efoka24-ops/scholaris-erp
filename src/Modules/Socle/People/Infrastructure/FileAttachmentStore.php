<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use InvalidArgumentException;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Contracts\FileScanner;
use Scholaris\Modules\Socle\People\Contracts\StoredAttachment;
use Scholaris\Modules\Socle\People\Domain\UploadPolicy;

/** AttachmentStore sur le stockage privé du socle (FileStorage), avec UploadPolicy et FileScanner. */
final class FileAttachmentStore implements AttachmentStore
{
    public function __construct(private readonly FileStorage $files, private readonly FileScanner $scanner)
    {
    }

    public function store(string $tenantId, string $area, string $ownerId, string $content): StoredAttachment
    {
        $type = UploadPolicy::inspect($content);
        if (!$this->scanner->isClean($content, $type['mime'])) {
            throw new InvalidArgumentException('Fichier refusé par l\'analyse de sécurité (contenu actif détecté).');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $area) !== 1) {
            throw new InvalidArgumentException('Domaine de stockage invalide.');
        }

        return new StoredAttachment($this->files->putIn($tenantId, $area, $ownerId, $type['ext'], $content), $type['mime'], $type['ext'], strlen($content));
    }

    public function read(string $tenantId, string $path): ?string
    {
        return $this->owned($tenantId, $path) ? $this->files->read($path) : null;
    }

    public function delete(string $tenantId, string $path): void
    {
        if ($this->owned($tenantId, $path)) {
            $this->files->delete($path);
        }
    }

    /** Une pièce n'est jamais lue pour un autre client : son chemin commence par le client, sans remontée de dossier. */
    private function owned(string $tenantId, string $path): bool
    {
        return $tenantId !== '' && str_starts_with($path, $tenantId . '/') && !str_contains($path, '..');
    }
}
