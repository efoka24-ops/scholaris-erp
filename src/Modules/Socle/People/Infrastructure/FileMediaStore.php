<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use InvalidArgumentException;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Domain\UrlSigner;

/**
 * MediaStore par composition sur AttachmentStore : contrôle du type, analyse du contenu et stockage privé sont délégués à
 * AttachmentStore ; ce store n'ajoute que l'espace de noms imposé, la taille maximale, la référence opaque et les URL signées.
 */
final class FileMediaStore implements MediaStore
{
    private const AREA_PREFIX = 'media-';
    private const NAMESPACE_PATTERN = '/^[a-z][a-z0-9-]{1,19}$/D';
    private const OWNER_PATTERN = '/^[A-Za-z0-9_-]{1,26}$/D';
    private const ROUTE_PATTERN = '#^/[A-Za-z0-9/_.-]*$#D';
    private const MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(
        private readonly AttachmentStore $attachments,
        private readonly UrlSigner $signer,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    public function put(string $tenantId, string $namespace, string $ownerId, string $content): MediaRef
    {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new InvalidArgumentException('Espace de noms de média invalide.');
        }
        if (preg_match(self::OWNER_PATTERN, $ownerId) !== 1) {
            throw new InvalidArgumentException('Propriétaire de média invalide.');
        }
        $max = $this->maxBytes();
        if (strlen($content) > $max) {
            throw new InvalidArgumentException(sprintf('Le fichier dépasse %s. Réduisez-le puis réessayez.', $this->readable($max)));
        }

        $stored = $this->attachments->store($tenantId, self::AREA_PREFIX . $namespace, $ownerId, $content);
        try {
            return MediaRef::compose($namespace, $ownerId, substr($stored->path, (int) strrpos($stored->path, '/') + 1));
        } catch (InvalidArgumentException $error) {
            $this->attachments->delete($tenantId, $stored->path);

            throw $error;
        }
    }

    public function signedUrl(string $tenantId, MediaRef $ref, int $ttl, string $route): string
    {
        if (preg_match(self::ROUTE_PATTERN, $route) !== 1 || str_starts_with($route, '//') || str_contains($route, '..')) {
            throw new InvalidArgumentException('Chemin d\'URL signée invalide.');
        }
        $expires = $this->clock->now() + max(1, min($ttl, self::MAX_TTL));

        return $route . '?' . http_build_query([
            'tenant' => $tenantId,
            'ref' => $ref->value,
            'expires' => $expires,
            'signature' => $this->signer->sign($this->subject($tenantId, $ref), $expires),
        ]);
    }

    public function readSigned(string $tenantId, MediaRef $ref, int $expires, string $signature): MediaContent
    {
        if (!$this->signer->verify($this->subject($tenantId, $ref), $expires, $signature, $this->clock->now())) {
            throw HttpException::forbidden('Lien invalide ou expiré. Demandez un nouveau lien.');
        }
        $content = $this->attachments->read($tenantId, $this->path($tenantId, $ref)) ?? throw HttpException::notFound('Média introuvable.');
        $extension = substr($ref->value, -3);

        return new MediaContent($content, self::MIME[$extension] ?? 'application/octet-stream', $extension, strlen($content));
    }

    public function delete(string $tenantId, MediaRef $ref): void
    {
        $this->attachments->delete($tenantId, $this->path($tenantId, $ref));
    }

    /** Chemin du média sous le client de l'appelant : le client n'est jamais lu dans la référence. */
    private function path(string $tenantId, MediaRef $ref): string
    {
        [$namespace, $ownerId, $fileName] = $ref->segments();

        return $tenantId . '/' . self::AREA_PREFIX . $namespace . '/' . $ownerId . '/' . $fileName;
    }

    /** Le préfixe `media` sépare ces liens de tout autre lien signé avec la même clé (pièces du dossier des personnes). */
    private function subject(string $tenantId, MediaRef $ref): string
    {
        return 'media|' . $tenantId . '|' . $ref->value;
    }

    private function maxBytes(): int
    {
        $value = $this->config->get('people.media.max_bytes', self::MAX_BYTES);

        return is_numeric($value) ? max(1, min((int) $value, AttachmentStore::MAX_BYTES)) : self::MAX_BYTES;
    }

    private function readable(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, ',', ''), '0'), ',') . ' Mo' : $bytes . ' octets';
    }
}
