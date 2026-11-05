<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use InvalidArgumentException;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/**
 * Photo du registre papier d'une inscription groupée (C-105, EX-ELV-07). Le fichier est contrôlé et rangé par MediaStore (espace
 * `public-schools`, propriétaire = l'identifiant de la photo) ; la ligne `pub_register_photos` ne garde que la référence opaque.
 * La photo est téléversée d'abord, puis jointe au lot par `register_photo_id` (une seule fois). Elle montre des noms d'élèves : lecture
 * par lien signé à durée limitée, réservée aux personnes qui peuvent inscrire.
 */
final class RegisterPhotoService
{
    public const MEDIA_SPACE = 'public-schools';
    public const URL_TTL = 600;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly MediaStore $media,
        private readonly PupilClock $clock,
    ) {
    }

    /** @return array{id: string, created_at: string} */
    public function add(string $tenantId, string $establishmentId, string $content, string $uploadedBy): array
    {
        $id = (string) new Ulid();
        try {
            $ref = $this->media->put($tenantId, self::MEDIA_SPACE, $id, $content);
        } catch (InvalidArgumentException $refusal) {
            throw HttpException::validation(['file' => [$refusal->getMessage()]]);
        }
        $at = $this->clock->now();
        $this->connections->pdo()->prepare('INSERT INTO pub_register_photos (id, tenant_id, establishment_id, file_path, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $ref->value, $uploadedBy, $at]);

        return ['id' => $id, 'created_at' => $at];
    }

    /** Vrai si la photo existe dans l'école et n'est jointe à aucun lot. */
    public function isAttachable(string $tenantId, string $establishmentId, string $photoId): bool
    {
        if (!Ulid::isValid($photoId) || $this->find($tenantId, $establishmentId, $photoId) === null) {
            return false;
        }
        $query = $this->connections->pdo()->prepare('SELECT COUNT(*) FROM pub_registration_batches WHERE tenant_id = ? AND register_photo_id = ?');
        $query->execute([$tenantId, $photoId]);

        return (int) $query->fetchColumn() === 0;
    }

    /** @return array{url: string, expires_in: int} lien signé vers le contenu de la photo du registre du lot */
    public function link(string $tenantId, string $establishmentId, string $batchId): array
    {
        $photo = $this->ofBatch($tenantId, $establishmentId, $batchId);

        return [
            'url' => $this->media->signedUrl($tenantId, new MediaRef((string) $photo['file_path']), self::URL_TTL, '/api/v1/public/pupils/batches/' . $batchId . '/register-photo/content'),
            'expires_in' => self::URL_TTL,
        ];
    }

    public function read(string $tenantId, string $establishmentId, string $batchId, int $expires, string $signature): MediaContent
    {
        $photo = $this->ofBatch($tenantId, $establishmentId, $batchId);

        return $this->media->readSigned($tenantId, new MediaRef((string) $photo['file_path']), $expires, $signature);
    }

    /** @return array<string, mixed> */
    private function ofBatch(string $tenantId, string $establishmentId, string $batchId): array
    {
        $query = $this->connections->pdo()->prepare('SELECT register_photo_id FROM pub_registration_batches WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $batchId]);
        $photoId = $query->fetchColumn();
        if (!is_string($photoId) || $photoId === '') {
            throw HttpException::notFound('Aucune photo de registre pour ce lot.');
        }

        return $this->find($tenantId, $establishmentId, $photoId) ?? throw HttpException::notFound('Photo du registre introuvable.');
    }

    /** @return array<string, mixed>|null */
    private function find(string $tenantId, string $establishmentId, string $photoId): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_register_photos WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $photoId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
