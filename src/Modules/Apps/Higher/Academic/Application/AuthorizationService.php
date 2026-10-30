<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationData;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;

/**
 * Registre des autorisations MINESUP (EX-ACA-03, T218) : n° et date de l'arrêté, filière, niveaux autorisés, validité, pièce
 * numérisée. Les valeurs vivent dans le registre du socle (D2, source unique lue par la garde d'inscription) ; hi_authorizations
 * rattache l'autorisation à l'école, au département et à la filière (périmètre) et garde la date de l'arrêté.
 * L'enregistrement d'une autorisation lève le blocage RG-ACA-1, rien d'autre.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly Store $store,
        private readonly AuthorizationRegistry $registry,
        private readonly Access $access,
        private readonly AcademicClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $v program_id, decree_number, decree_date, authority, valid_from, valid_to, level_ids, document_id
     * @return array{0: array<string, mixed>, 1: ?AuthorizationInfo}
     */
    public function register(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $v['program_id'])
            ?? throw HttpException::validation(['program_id' => ['Filière introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, StructureService::scopeOf('program', $program), 'higher_authorization', (string) $program['id']);
        $levels = $this->levels($tenantId, $establishmentId, (string) $program['id'], $v['level_ids'] ?? null);

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $program, $levels, $v): array {
            $id = $this->registry->register($tenantId, $establishmentId, $this->data((string) $program['id'], $v, $levels), $principal->userId);
            $now = $this->clock->now();
            $this->store->insert('hi_authorizations', $tenantId, $establishmentId, [
                'id' => $id, 'school_id' => $program['school_id'], 'department_id' => $program['department_id'], 'program_id' => $program['id'],
                'decree_date' => $v['decree_date'] ?? null, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = $this->store->findOrFail('hi_authorizations', $tenantId, $establishmentId, $id);
            $this->access->audit($principal, 'higher.authorization.registered', 'higher_authorization', $id, null, $row + ['decree_number' => $v['decree_number']]);

            return [$row, $this->registry->find($tenantId, $establishmentId, $id)];
        });
    }

    /**
     * Remplace les valeurs (la filière ne change pas).
     *
     * @param array<string, mixed> $v
     * @return array{0: array<string, mixed>, 1: ?AuthorizationInfo}
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        [$row, $info] = $this->writable($principal, $establishmentId, $id);
        $merged = [
            'decree_number' => $v['decree_number'] ?? $info->reference,
            'authority' => array_key_exists('authority', $v) ? $v['authority'] : $info->authority,
            'valid_from' => $v['valid_from'] ?? $info->validFrom,
            'valid_to' => $v['valid_to'] ?? $info->validTo,
            'document_id' => array_key_exists('document_id', $v) ? $v['document_id'] : $info->documentId,
        ];
        if ($merged['valid_to'] < $merged['valid_from']) {
            throw HttpException::validation(['valid_to' => ['La fin de validité ne peut pas précéder le début.']]);
        }
        $levels = array_key_exists('level_ids', $v) ? $this->levels($tenantId, $establishmentId, (string) $row['program_id'], $v['level_ids']) : $info->coveredLevels;

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $id, $row, $merged, $levels, $v): array {
            $this->registry->update($tenantId, $establishmentId, $id, $this->data((string) $row['program_id'], $merged, $levels), $principal->userId);
            $changes = ['updated_at' => $this->clock->now()];
            if (array_key_exists('decree_date', $v)) {
                $changes['decree_date'] = $v['decree_date'];
            }
            $this->store->update('hi_authorizations', $tenantId, $establishmentId, $changes, ['id' => $id]);
            $after = $this->store->findOrFail('hi_authorizations', $tenantId, $establishmentId, $id);
            $this->access->audit($principal, 'higher.authorization.updated', 'higher_authorization', $id, $row, $after);

            return [$after, $this->registry->find($tenantId, $establishmentId, $id)];
        });
    }

    public function remove(Principal $principal, string $establishmentId, string $id): void
    {
        $tenantId = $principal->tenantId;
        [$row] = $this->writable($principal, $establishmentId, $id);
        $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $id, $row): void {
            $this->registry->remove($tenantId, $establishmentId, $id, $principal->userId);
            $this->store->delete('hi_authorizations', $tenantId, $establishmentId, ['id' => $id]);
            $this->access->audit($principal, 'higher.authorization.removed', 'higher_authorization', $id, $row, null);
        });
    }

    /** @return array{0: array<string, mixed>, 1: AuthorizationInfo} */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $row = $this->store->findOrFail('hi_authorizations', $tenantId, $establishmentId, $id, 'Autorisation introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scope($row), 'higher_authorization', $id);
        $info = $this->registry->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Autorisation introuvable dans le registre.');

        return [$row, $info];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    public static function scope(array $row): array
    {
        return ['school' => (string) $row['school_id'], 'department' => (string) $row['department_id'], 'program' => (string) $row['program_id']];
    }

    /**
     * Niveaux couverts : niveaux de CETTE filière ; absent ou null = tous les niveaux.
     *
     * @return list<string>|null
     */
    private function levels(string $tenantId, string $establishmentId, string $programId, mixed $levelIds): ?array
    {
        if ($levelIds === null) {
            return null;
        }
        $levels = [];
        foreach ((array) $levelIds as $levelId) {
            $level = $this->store->find('hi_levels', $tenantId, $establishmentId, (string) $levelId);
            if ($level === null || $level['program_id'] !== $programId) {
                throw HttpException::validation(['level_ids' => ['Chaque niveau autorisé doit être un niveau de la filière.']]);
            }
            $levels[] = (string) $levelId;
        }

        return $levels === [] ? null : $levels;
    }

    /**
     * @param array<string, mixed> $v
     * @param list<string>|null $levels
     */
    private function data(string $programId, array $v, ?array $levels): AuthorizationData
    {
        return new AuthorizationData(
            $programId,
            (string) $v['decree_number'],
            (string) $v['valid_from'],
            (string) $v['valid_to'],
            isset($v['authority']) ? (string) $v['authority'] : 'MINESUP',
            $levels,
            isset($v['document_id']) ? (string) $v['document_id'] : null,
        );
    }
}
