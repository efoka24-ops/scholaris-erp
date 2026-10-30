<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

final class AuthorizedPersonsService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AuthorizedPickupRegistry $pickups,
        private readonly MediaStore $media,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM authorized_pickups WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? ORDER BY created_at, id',
        );
        $statement->execute([$tenantId, $establishmentId, $learnerId]);

        return array_values(array_map(self::cast(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, string $learnerId, string $actorId, array $data): array
    {
        $payload = $data;
        $content = $this->decodePhoto($data);
        if ($content !== null) {
            $payload['photo_ref'] = (string) $this->media->put($tenantId, 'public-nursery', $this->ownerId($learnerId), $content);
        }
        $id = $this->pickups->grant($tenantId, $establishmentId, $learnerId, $payload, $actorId);

        return $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Autorisation introuvable après création.');
    }

    /** @return array<string, mixed> */
    public function revoke(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $this->pickups->revoke($tenantId, $establishmentId, $id, $actorId);

        return $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Autorisation introuvable après révocation.');
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM authorized_pickups WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string, mixed> $data */
    private function decodePhoto(array $data): ?string
    {
        $content = $data['photo_content'] ?? $data['photo_content_base64'] ?? null;
        if ($content === null || $content === '') {
            return null;
        }
        if (!is_string($content)) {
            throw HttpException::validation(['photo_content' => ['Texte attendu.']]);
        }
        $decoded = array_key_exists('photo_content_base64', $data) ? base64_decode($content, true) : $content;
        if ($decoded === false || $decoded === '') {
            throw HttpException::validation(['photo_content' => ['Contenu photo invalide.']]);
        }

        return $decoded;
    }

    private function ownerId(string $learnerId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', substr($learnerId, 0, 26)) ?: 'pickup-photo';
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'establishment_id' => (string) $row['establishment_id'],
            'learner_id' => (string) $row['learner_id'],
            'pickup_name' => (string) $row['pickup_name'],
            'relationship' => (string) $row['relationship'],
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'photo_document_ref' => $row['photo_document_ref'] !== null ? (string) $row['photo_document_ref'] : null,
            'grant_type' => (string) $row['grant_type'],
            'valid_on' => $row['valid_on'] !== null ? (string) $row['valid_on'] : null,
            'granted_by' => (string) $row['granted_by'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
