<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;

/**
 * Lien public « Candidater » d'un établissement, géré par la scolarité (higher.application.manage) : création, désactivation et
 * renouvellement du jeton (l'ancien lien cesse aussitôt de répondre). Le jeton est tiré au hasard (cryptographiquement) ; seul un
 * préfixe lisible tiré du nom de l'institut le précède, pour qu'un lien partagé sur WhatsApp inspire confiance sans être devinable.
 */
final class PublicLinkService
{
    public const TABLE = 'hi_public_links';
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const RANDOM_LENGTH = 10;

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AdmissionsAccess $access,
        private readonly EstablishmentDirectory $establishments,
        private readonly AdmissionsClock $clock,
    ) {
    }

    /** @return array{exists: bool, enabled: bool, slug: ?string, path: ?string} */
    public function show(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, [AdmissionPermissions::MANAGE]);

        return self::resource($this->store->select(self::TABLE, $principal->tenantId, $establishmentId, [], 'id', 1)[0] ?? null);
    }

    /** @return array{exists: bool, enabled: bool, slug: ?string, path: ?string} */
    public function update(Principal $principal, string $establishmentId, bool $enabled, bool $rotate): array
    {
        $this->access->require($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        $tenantId = $principal->tenantId;
        $current = $this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'id', 1)[0] ?? null;
        $now = $this->clock->now();
        $establishment = $this->establishments->find($tenantId, $establishmentId) ?? throw HttpException::notFound('Établissement introuvable.');
        $name = $establishment->name;
        if ($current === null) {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'slug' => self::newSlug($name), 'enabled' => $enabled ? 1 : 0,
                'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->access->audit($tenantId, $principal->userId, 'higher.public_link.created', 'hi_public_link', $establishmentId, null, ['enabled' => $enabled]);
        } else {
            $changes = ['enabled' => $enabled ? 1 : 0, 'updated_at' => $now];
            if ($rotate) {
                $changes['slug'] = self::newSlug($name);
            }
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $current['id']]);
            $this->access->audit($tenantId, $principal->userId, $rotate ? 'higher.public_link.rotated' : 'higher.public_link.updated', 'hi_public_link', $establishmentId, ['enabled' => (int) $current['enabled'] === 1], ['enabled' => $enabled]);
        }

        return self::resource($this->store->select(self::TABLE, $tenantId, $establishmentId, [], 'id', 1)[0] ?? null);
    }

    /** Préfixe lisible (lettres du nom, 12 au plus) puis 10 caractères tirés au hasard (environ 49 bits). */
    public static function newSlug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $prefix = substr(strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $ascii === false ? '' : $ascii)), 0, 12);
        $random = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $prefix === '' ? $random : $prefix . '-' . $random;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{exists: bool, enabled: bool, slug: ?string, path: ?string}
     */
    private static function resource(?array $row): array
    {
        if ($row === null) {
            return ['exists' => false, 'enabled' => false, 'slug' => null, 'path' => null];
        }

        return ['exists' => true, 'enabled' => (int) $row['enabled'] === 1, 'slug' => (string) $row['slug'], 'path' => '/candidater/' . $row['slug']];
    }
}
