<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\MediaContent;
use Scholaris\Modules\Socle\People\Contracts\MediaRef;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;

/** Doublure de MediaStore : octets en mémoire par client, URL signée factice, lecture signée vérifiée. */
final class WorldMedia implements MediaStore
{
    /** @var array<string, string> « client|référence » => octets */
    public array $files = [];

    public function put(string $tenantId, string $namespace, string $ownerId, string $content): MediaRef
    {
        if ($content === '') {
            throw new \InvalidArgumentException('Média vide.');
        }
        $ref = MediaRef::compose($namespace, $ownerId, (string) new Ulid() . '.jpg');
        $this->files[$tenantId . '|' . $ref->value] = $content;

        return $ref;
    }

    public function signedUrl(string $tenantId, MediaRef $ref, int $ttl, string $route): string
    {
        return $route . '?tenant=' . $tenantId . '&ref=' . $ref->value . '&expires=9999999999&signature=ok';
    }

    public function readSigned(string $tenantId, MediaRef $ref, int $expires, string $signature): MediaContent
    {
        $content = $this->files[$tenantId . '|' . $ref->value] ?? throw HttpException::notFound('Média introuvable.');

        return new MediaContent($content, 'image/jpeg', 'jpg', strlen($content));
    }

    public function delete(string $tenantId, MediaRef $ref): void
    {
        unset($this->files[$tenantId . '|' . $ref->value]);
    }
}
