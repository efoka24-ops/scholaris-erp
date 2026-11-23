<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Organisation\Contracts\LogoImage;
use Scholaris\Modules\Socle\Organisation\Domain\LogoProcessor;
use Scholaris\Modules\Socle\Organisation\Infrastructure\EstablishmentMapper;
use Scholaris\Modules\Socle\Organisation\Infrastructure\LogoStorage;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Logo d'un établissement : un seul à la fois (le remplacer supprime l'ancien fichier). Toutes les opérations sont bornées au client. */
final class LogoService
{
    public function __construct(
        private readonly Repositories $repositories,
        private readonly EstablishmentService $establishments,
        private readonly LogoStorage $storage,
    ) {
    }

    /**
     * @return array<string, mixed> identité visuelle après remplacement
     * @throws HttpException 422 si l'image est refusée
     */
    public function replace(string $tenantId, string $establishmentId, string $bytes): array
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->findOrFail($establishmentId);
        try {
            $image = LogoProcessor::process($bytes);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['file' => [$e->getMessage()]]);
        }
        $previous = self::path($row);
        $path = $this->storage->put($tenantId, (string) $row['id'], $image['extension'], $image['content']);
        try {
            $updated = $this->establishments->updateBranding($tenantId, (string) $row['id'], [
                'logo' => ['path' => $path, 'mime' => $image['mime'], 'size' => strlen($image['content'])],
            ]);
        } catch (\Throwable $e) {
            $this->storage->delete($path);

            throw $e;
        }
        if ($previous !== null && $previous !== $path) {
            $this->storage->delete($previous);
        }

        return EstablishmentMapper::decode((string) $updated['branding']);
    }

    /** @return array<string, mixed> identité visuelle après suppression (sans effet si aucun logo) */
    public function remove(string $tenantId, string $establishmentId): array
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->findOrFail($establishmentId);
        $previous = self::path($row);
        if ($previous === null) {
            return EstablishmentMapper::decode((string) $row['branding']);
        }
        $updated = $this->establishments->updateBranding($tenantId, (string) $row['id'], ['logo' => null]);
        $this->storage->delete($previous);

        return EstablishmentMapper::decode((string) $updated['branding']);
    }

    /** Logo propre à l'établissement (sans repli), ou null. */
    public function read(string $tenantId, string $establishmentId): ?LogoImage
    {
        $row = $this->repositories->explicit('establishments', $tenantId)->find($establishmentId);
        if ($row === null) {
            return null;
        }

        return $this->load(self::path($row));
    }

    public function load(?string $path): ?LogoImage
    {
        if ($path === null) {
            return null;
        }
        $content = $this->storage->read($path);
        $info = $content === null ? false : @getimagesizefromstring($content);
        if ($content === null || $info === false || !in_array($info['mime'], ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return new LogoImage((string) $info['mime'], $content, (int) $info[0], (int) $info[1]);
    }

    /** @param array<string, mixed> $row */
    private static function path(array $row): ?string
    {
        $logo = EstablishmentMapper::decode((string) $row['branding'])['logo'] ?? null;

        return is_array($logo) && is_string($logo['path'] ?? null) ? $logo['path'] : null;
    }
}
