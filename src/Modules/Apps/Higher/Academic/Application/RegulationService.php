<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationBook;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\RegulationInfo;
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Domain\RegulationParameters;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Règlement des études par école, régime et année (EX-ACA-05) : formules, seuils, compensation, rattrapage, passage.
 * Brouillon modifiable (higher.settings.manage) ; figé à l'ouverture des inscriptions ; un règlement figé ne se modifie que par
 * le directeur académique (higher.regulation.amend) avec une double authentification récente, motif obligatoire, version + 1,
 * révision conservée et journalisée (RG-ACA-3).
 */
final class RegulationService implements RegulationBook
{
    public const DRAFT = 'draft';
    public const FROZEN = 'frozen';
    public const SECOND_FACTOR_TYPE = 'https://api.scholaris.cm/errors/second-factor-required';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicClock $clock,
        private readonly RecentSecondFactor $secondFactor,
        private readonly OrgUnitSettingsReader $settings,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $v school_id, regime, academic_year_id, parameters
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::MANAGE]);
        $school = $this->store->find('hi_schools', $tenantId, $establishmentId, (string) $v['school_id'])
            ?? throw HttpException::validation(['school_id' => ['École introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, ['school' => (string) $school['id']], 'higher_regulation', (string) $school['id']);
        $where = ['school_id' => $school['id'], 'regime' => $v['regime'], 'academic_year_id' => $v['academic_year_id']];
        if ($this->store->count('hi_regulations', $tenantId, $establishmentId, $where) > 0) {
            throw HttpException::conflict('Un règlement existe déjà pour cette école, ce régime et cette année.');
        }
        [$parameters, $errors] = RegulationParameters::merge(RegulationParameters::defaults(Regime::from((string) $v['regime'])), (array) ($v['parameters'] ?? []));
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_regulations', $tenantId, $establishmentId, $where + [
            'id' => $id, 'version' => 1, 'parameters' => self::json($parameters), 'status' => self::DRAFT,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_regulations', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.regulation.created', 'higher_regulation', $id, null, $row);

        return $row;
    }

    /**
     * Brouillon seulement ; un règlement figé passe par amend().
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function updateDraft(Principal $principal, string $establishmentId, string $id, array $overrides): array
    {
        $row = $this->writable($principal, $establishmentId, $id, Permissions::MANAGE);
        if ($row['status'] !== self::DRAFT) {
            throw new HttpException(
                409,
                'Règlement figé',
                'Le règlement est figé depuis l\'ouverture des inscriptions (RG-ACA-3) : sa modification relève du directeur académique (POST /higher/regulations/{id}/amend, double authentification).',
                'https://api.scholaris.cm/errors/higher-regulation-frozen',
            );
        }
        $parameters = $this->merged($row, $overrides);
        $this->store->update('hi_regulations', $principal->tenantId, $establishmentId, ['parameters' => self::json($parameters), 'updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->store->findOrFail('hi_regulations', $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.regulation.updated', 'higher_regulation', $id, $row, $after);

        return $after;
    }

    /** @return array<string, mixed> */
    public function freeze(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, Permissions::MANAGE);
        if ($row['status'] === self::FROZEN) {
            throw HttpException::conflict('Ce règlement est déjà figé.');
        }
        $this->doFreeze($principal->tenantId, $establishmentId, $row, $principal->userId);

        return $this->store->findOrFail('hi_regulations', $principal->tenantId, $establishmentId, $id);
    }

    /**
     * RG-ACA-3 : modification d'un règlement figé par le directeur académique, double authentification récente, motif.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function amend(Principal $principal, string $establishmentId, string $id, array $overrides, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, Permissions::AMEND);
        if ($row['status'] !== self::FROZEN) {
            throw HttpException::conflict('Ce règlement est encore en brouillon : modifiez-le directement (PATCH).');
        }
        $maxAge = (int) $this->settings->valueForOrgUnit($tenantId, $establishmentId, (string) $row['school_id'], 'higher.security.recent_2fa_seconds', 600);
        if (!$this->secondFactor->hasRecent($tenantId, $principal->userId, max(60, $maxAge))) {
            $this->access->audit($principal, 'higher.regulation.amend_refused', 'higher_regulation', $id, null, ['reason' => 'second_factor']);
            throw new HttpException(403, 'Double authentification requise', 'Validez votre double authentification avant de modifier un règlement figé (RG-ACA-3).', self::SECOND_FACTOR_TYPE);
        }
        $parameters = $this->merged($row, $overrides);
        $version = (int) $row['version'] + 1;
        $now = $this->clock->now();

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $id, $row, $parameters, $version, $reason, $now): array {
            $this->store->insert('hi_regulation_revisions', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'regulation_id' => $id, 'version' => $version, 'previous_parameters' => (string) $row['parameters'],
                'new_parameters' => self::json($parameters), 'reason' => $reason, 'amended_by' => $principal->userId, 'created_at' => $now,
            ]);
            $this->store->update('hi_regulations', $tenantId, $establishmentId, ['parameters' => self::json($parameters), 'version' => $version, 'updated_at' => $now], ['id' => $id]);
            $after = $this->store->findOrFail('hi_regulations', $tenantId, $establishmentId, $id);
            $this->access->audit($principal, 'higher.regulation.amended', 'higher_regulation', $id, $row, $after + ['reason' => $reason]);

            return $after;
        });
    }

    public function effective(string $tenantId, string $establishmentId, string $schoolId, string $regime, string $academicYearId): ?RegulationInfo
    {
        $row = $this->store->select('hi_regulations', $tenantId, $establishmentId, ['school_id' => $schoolId, 'regime' => $regime, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? null;

        return $row === null ? null : self::info($row);
    }

    public function freezeForEnrolment(string $tenantId, string $establishmentId, string $schoolId, string $regime, string $academicYearId, ?string $actorId = null): ?RegulationInfo
    {
        $row = $this->store->select('hi_regulations', $tenantId, $establishmentId, ['school_id' => $schoolId, 'regime' => $regime, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        if ($row['status'] !== self::FROZEN) {
            $this->doFreeze($tenantId, $establishmentId, $row, $actorId);
            $row = $this->store->findOrFail('hi_regulations', $tenantId, $establishmentId, (string) $row['id']);
        }

        return self::info($row);
    }

    /** @param array<string, mixed> $row */
    private function doFreeze(string $tenantId, string $establishmentId, array $row, ?string $actorId): void
    {
        $now = $this->clock->now();
        $this->store->update('hi_regulations', $tenantId, $establishmentId, ['status' => self::FROZEN, 'frozen_at' => $now, 'frozen_by' => $actorId, 'updated_at' => $now], ['id' => (string) $row['id']]);
        $this->audit->append($tenantId, $actorId, 'higher.regulation.frozen', 'higher_regulation', (string) $row['id'], ['status' => $row['status']], ['status' => self::FROZEN]);
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id, string $permission): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [$permission]);
        $row = $this->store->findOrFail('hi_regulations', $principal->tenantId, $establishmentId, $id, 'Règlement introuvable.');
        $this->access->assertWritable($principal, $perimeter, ['school' => (string) $row['school_id']], 'higher_regulation', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     * @return array<string, bool|float|int|null>
     */
    private function merged(array $row, array $overrides): array
    {
        $current = json_decode((string) $row['parameters'], true);
        [$parameters, $errors] = RegulationParameters::merge(is_array($current) ? $current : [], $overrides);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $parameters;
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): RegulationInfo
    {
        $parameters = json_decode((string) $row['parameters'], true);

        return new RegulationInfo(
            (string) $row['id'],
            (string) $row['school_id'],
            (string) $row['regime'],
            (string) $row['academic_year_id'],
            (int) $row['version'],
            (string) $row['status'],
            is_array($parameters) ? $parameters : [],
        );
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
