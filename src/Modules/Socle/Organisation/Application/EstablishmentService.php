<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Domain\AccentColor;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\ModuleCatalog;
use Scholaris\Modules\Socle\Organisation\Infrastructure\EstablishmentMapper;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Établissements d'un client. Le type est fixé à la création et ne change jamais : aucune méthode ne le modifie.
 * Toutes les opérations sont bornées au client indiqué.
 */
final class EstablishmentService
{
    public function __construct(
        private readonly Repositories $repositories,
        private readonly OrgClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $tenantId, string $name, EstablishmentType $type, ?string $subtype = null): array
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $branding = [
            'accent' => AccentColor::DEFAULT,
            'logo' => null,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'headers' => [],
            'signatories' => [],
            'authorization_numbers' => [],
        ];

        $repository = $this->repositories->explicit('establishments', $tenantId);
        try {
            $repository->insert([
                'id' => $id,
                'name' => $name,
                'type' => $type->value,
                'establishment_subtype' => $subtype,
                'branding' => $this->json($branding),
                'modules_enabled' => $this->json(ModuleCatalog::KEYS),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            $this->rethrowConflict($e);
        }

        return $repository->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function rename(string $tenantId, string $id, string $name): array
    {
        $repository = $this->repositories->explicit('establishments', $tenantId);
        $repository->findOrFail($id);
        try {
            $repository->update($id, ['name' => $name, 'updated_at' => $this->clock->now()]);
        } catch (PDOException $e) {
            $this->rethrowConflict($e);
        }

        return $repository->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $changes champs d'identité visuelle déjà validés
     * @return array<string, mixed>
     */
    public function updateBranding(string $tenantId, string $id, array $changes): array
    {
        $repository = $this->repositories->explicit('establishments', $tenantId);
        $row = $repository->findOrFail($id);
        $branding = array_replace(EstablishmentMapper::decode((string) $row['branding']), $changes);
        $repository->update($id, ['branding' => $this->json($branding), 'updated_at' => $this->clock->now()]);

        return $repository->findOrFail($id);
    }

    /**
     * @param list<string> $modules clés de ModuleCatalog
     * @return array<string, mixed>
     */
    public function setModules(string $tenantId, string $id, array $modules): array
    {
        $repository = $this->repositories->explicit('establishments', $tenantId);
        $repository->findOrFail($id);
        $repository->update($id, ['modules_enabled' => $this->json($modules), 'updated_at' => $this->clock->now()]);

        return $repository->findOrFail($id);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function rethrowConflict(PDOException $e): never
    {
        if (str_contains($e->getMessage(), 'UNIQUE') || in_array((string) $e->getCode(), ['23000', '23505'], true)) {
            throw HttpException::conflict('Un établissement de ce nom existe déjà pour ce client.');
        }
        throw $e;
    }
}
