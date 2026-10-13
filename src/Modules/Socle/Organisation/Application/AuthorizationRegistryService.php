<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DateTimeInterface;
use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Http\Resources\AuthorizationResource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\PdoOrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/**
 * Registre des autorisations (D2). Une autorisation porte sur une structure (souvent la filière) et couvre ses sous-structures ;
 * `covered_levels` restreint les niveaux couverts (NULL = tous, comportement historique). Les mêmes contrôles servent à la route
 * /authorizations (coveredLevelErrors).
 */
final class AuthorizationRegistryService implements AuthorizationRegistry
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly Repositories $repositories,
        private readonly PdoOrgUnitTree $tree,
        private readonly AuditLog $audit,
        private readonly OrgClock $clock,
    ) {
    }

    public function register(string $tenantId, string $establishmentId, AuthorizationData $data, ?string $actorId = null): string
    {
        $this->assertEstablishment($tenantId, $establishmentId);
        $errors = $this->errors($tenantId, $establishmentId, $data);
        if ($this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->find($data->structureId) === null) {
            $errors['structure_id'] = ['Structure introuvable dans cet établissement.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $table = $this->repositories->explicit('authorizations', $tenantId, $establishmentId);
        $this->guarded(fn () => $table->insert(['id' => $id, 'structure_id' => $data->structureId] + $this->columns($data) + ['created_at' => $now, 'updated_at' => $now]));
        $this->audit->append($tenantId, $actorId, 'authorization.created', 'authorization', $id, null, (new AuthorizationResource())->toArray($table->findOrFail($id)));

        return $id;
    }

    public function update(string $tenantId, string $establishmentId, string $authorizationId, AuthorizationData $data, ?string $actorId = null): void
    {
        $table = $this->repositories->explicit('authorizations', $tenantId, $establishmentId);
        $existing = $table->find($authorizationId) ?? throw HttpException::notFound('Autorisation introuvable dans cet établissement.');
        $errors = $this->errors($tenantId, $establishmentId, $data);
        if ($data->structureId !== (string) $existing['structure_id']) {
            $errors['structure_id'] = ['Ce champ ne peut plus être modifié après la création.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->guarded(fn () => $table->update($authorizationId, $this->columns($data) + ['updated_at' => $this->clock->now()]));
        $resource = new AuthorizationResource();
        $this->audit->append($tenantId, $actorId, 'authorization.updated', 'authorization', $authorizationId, $resource->toArray($existing), $resource->toArray($table->findOrFail($authorizationId)));
    }

    public function remove(string $tenantId, string $establishmentId, string $authorizationId, ?string $actorId = null): void
    {
        $table = $this->repositories->explicit('authorizations', $tenantId, $establishmentId);
        $existing = $table->find($authorizationId) ?? throw HttpException::notFound('Autorisation introuvable dans cet établissement.');
        $table->delete($authorizationId);
        $this->audit->append($tenantId, $actorId, 'authorization.removed', 'authorization', $authorizationId, (new AuthorizationResource())->toArray($existing), null);
    }

    public function find(string $tenantId, string $establishmentId, string $authorizationId): ?AuthorizationInfo
    {
        $row = $this->repositories->explicit('authorizations', $tenantId, $establishmentId)->find($authorizationId);

        return $row === null ? null : self::info($row);
    }

    public function forStructure(string $tenantId, string $establishmentId, string $structureId): array
    {
        return array_map(
            self::info(...),
            $this->repositories->explicit('authorizations', $tenantId, $establishmentId)->all(['structure_id' => $structureId], 'valid_from'),
        );
    }

    public function covers(string $tenantId, string $establishmentId, string $structureId, ?string $levelId, DateTimeInterface $on): bool
    {
        $lineage = $this->tree->lineage($tenantId, $establishmentId, $structureId);
        if ($lineage === []) {
            return false;
        }
        $day = $this->clock->day($on);
        $level = $levelId ?? $this->tree->levelOf($tenantId, $establishmentId, $structureId);
        foreach ($lineage as $id) {
            foreach ($this->forStructure($tenantId, $establishmentId, $id) as $authorization) {
                if ($authorization->isValidOn($day) && $authorization->coversLevel($level)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Niveaux couverts : structures de nature `level` de l'établissement.
     *
     * @param list<string> $levels
     * @return array<string, list<string>>
     */
    public function coveredLevelErrors(string $tenantId, string $establishmentId, array $levels): array
    {
        $structures = $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId);
        foreach ($levels as $levelId) {
            $row = $structures->find($levelId);
            if ($row === null || $row['kind'] !== 'level') {
                return ['covered_levels' => ['Chaque niveau couvert doit être une structure de niveau de cet établissement.']];
            }
        }

        return [];
    }

    /** @param array<string, mixed> $row */
    public static function info(array $row): AuthorizationInfo
    {
        $levels = null;
        if (isset($row['covered_levels']) && is_string($row['covered_levels']) && $row['covered_levels'] !== '') {
            $decoded = json_decode($row['covered_levels'], true);
            $levels = is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
        }

        return new AuthorizationInfo(
            (string) $row['id'],
            (string) $row['establishment_id'],
            (string) $row['structure_id'],
            (string) $row['reference'],
            $row['authority'] === null ? null : (string) $row['authority'],
            (string) $row['valid_from'],
            (string) $row['valid_to'],
            $levels,
            isset($row['document_id']) ? (string) $row['document_id'] : null,
        );
    }

    /** @return array<string, scalar|null> */
    private function columns(AuthorizationData $data): array
    {
        return [
            'reference' => trim($data->reference),
            'authority' => $data->authority === null ? null : trim($data->authority),
            'valid_from' => $data->validFrom,
            'valid_to' => $data->validTo,
            'covered_levels' => $data->coveredLevels === null ? null : json_encode(array_values(array_unique($data->coveredLevels)), JSON_THROW_ON_ERROR),
            'document_id' => $data->documentId,
        ];
    }

    /** @return array<string, list<string>> */
    private function errors(string $tenantId, string $establishmentId, AuthorizationData $data): array
    {
        $errors = [];
        $reference = mb_strlen(trim($data->reference));
        if ($reference < 1 || $reference > 80) {
            $errors['reference'] = ['La référence doit contenir entre 1 et 80 caractères.'];
        }
        if ($data->authority !== null && mb_strlen($data->authority) > 160) {
            $errors['authority'] = ['L\'autorité ne doit pas dépasser 160 caractères.'];
        }
        foreach (['valid_from' => $data->validFrom, 'valid_to' => $data->validTo] as $field => $value) {
            if (preg_match(self::DATE, $value) !== 1 || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                $errors[$field] = ['Date invalide (AAAA-MM-JJ).'];
            }
        }
        if (!isset($errors['valid_from'], $errors['valid_to']) && $data->validTo < $data->validFrom) {
            $errors['valid_to'] = ['La fin de validité ne peut pas précéder le début.'];
        }
        if ($data->documentId !== null && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $data->documentId) !== 1) {
            $errors['document_id'] = ['Identifiant invalide.'];
        }
        if ($data->coveredLevels !== null) {
            $errors += $this->coveredLevelErrors($tenantId, $establishmentId, $data->coveredLevels);
        }

        return $errors;
    }

    private function assertEstablishment(string $tenantId, string $establishmentId): void
    {
        if ($this->repositories->explicit('establishments', $tenantId)->find($establishmentId) === null) {
            throw HttpException::notFound('Établissement introuvable.');
        }
    }

    /** @param callable(): mixed $write */
    private function guarded(callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw HttpException::conflict('Une autorisation de même référence existe déjà pour cette structure.');
            }
            throw $e;
        }
    }
}
