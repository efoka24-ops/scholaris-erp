<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/**
 * Photos géolocalisées de l'école prises lors des visites de l'inspection (EX-ECO-04). Le fichier est contrôlé et stocké par
 * MediaStore (espace `public-schools`, propriétaire = fiche de l'école) ; la ligne ne garde que la référence opaque.
 */
final class SchoolPhotoService
{
    public const MEDIA_SPACE = 'public-schools';
    public const URL_TTL = 600;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly MediaStore $media,
        private readonly SchoolFicheService $fiches,
    ) {
    }

    /** @return array<string, mixed> */
    public function add(string $tenantId, string $establishmentId, string $content, mixed $latitude, mixed $longitude, mixed $takenAt, ?string $visitId, string $takenBy): array
    {
        $fiche = $this->fiches->find($tenantId, $establishmentId) ?? throw HttpException::notFound('Fiche école introuvable.');
        $errors = [];
        if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90) {
            $errors['latitude'][] = 'Latitude numérique comprise entre -90 et 90 requise.';
        }
        if (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
            $errors['longitude'][] = 'Longitude numérique comprise entre -180 et 180 requise.';
        }
        $taken = is_string($takenAt) ? date_create_immutable($takenAt) : false;
        if ($taken === false) {
            $errors['taken_at'][] = 'Date et heure de prise de vue (ISO 8601) requises.';
        }
        if ($visitId !== null && !Ulid::isValid($visitId)) {
            $errors['visit_id'][] = 'Identifiant de visite invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        try {
            $ref = $this->media->put($tenantId, self::MEDIA_SPACE, (string) $fiche['id'], $content);
        } catch (\InvalidArgumentException $refusal) {
            throw HttpException::validation(['file' => [$refusal->getMessage()]]);
        }
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO pub_school_photos (id, tenant_id, establishment_id, file_path, latitude, longitude, taken_at, visit_id, taken_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $id, $tenantId, $establishmentId, $ref->value, number_format((float) $latitude, 6, '.', ''), number_format((float) $longitude, 6, '.', ''),
            $taken instanceof DateTimeImmutable ? $taken->format(DATE_ATOM) : '', $visitId, $takenBy,
        ]);

        return $this->present($tenantId, $this->require($tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_school_photos WHERE tenant_id = ? AND establishment_id = ? ORDER BY taken_at DESC, id DESC');
        $query->execute([$tenantId, $establishmentId]);

        return array_values(array_map(fn (array $row): array => $this->present($tenantId, $row), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** Contenu d'une photo par lien signé : signature et expiration vérifiées avant toute lecture. */
    public function read(string $tenantId, string $establishmentId, string $id, int $expires, string $signature): MediaContent
    {
        $row = $this->require($tenantId, $establishmentId, $id);

        return $this->media->readSigned($tenantId, new MediaRef((string) $row['file_path']), $expires, $signature);
    }

    /** @return array<string, mixed> */
    private function require(string $tenantId, string $establishmentId, string $id): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_school_photos WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : throw HttpException::notFound('Photo introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(string $tenantId, array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'establishment_id' => (string) $row['establishment_id'],
            'latitude' => (string) $row['latitude'],
            'longitude' => (string) $row['longitude'],
            'taken_at' => (string) $row['taken_at'],
            'visit_id' => $row['visit_id'] !== null ? (string) $row['visit_id'] : null,
            'taken_by' => (string) $row['taken_by'],
            'url' => $this->media->signedUrl($tenantId, new MediaRef((string) $row['file_path']), self::URL_TTL, '/api/v1/public/schools/photos/' . $row['id'] . '/content'),
        ];
    }
}
