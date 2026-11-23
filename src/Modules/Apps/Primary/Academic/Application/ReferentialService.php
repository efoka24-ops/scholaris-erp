<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Academic\Domain\EvaluationMode;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/**
 * Référentiel du primaire (EX-PRI-05, 16, 17) : profils de niveau, domaines, matières par niveau et compétences. Les niveaux et
 * les matières vivent dans le socle (StructureWriter, CurriculumCatalog) ; ici seules les extensions `pri_*`. Écriture réservée à
 * la direction pédagogique sur tout l'établissement (RG-PRI-3). Aucune liste de niveaux, de matières ou de compétences n'est codée.
 */
final class ReferentialService
{
    public const TABLE_PROFILES = 'pri_level_profiles';
    public const TABLE_DOMAINS = 'pri_domains';
    public const TABLE_SUBJECTS = 'pri_level_subjects';
    public const TABLE_COMPETENCIES = 'pri_competencies';
    public const SUBJECT_KEY_PREFIX = 'pri:';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly OrgUnitTree $tree,
        private readonly StructureWriter $structures,
        private readonly CurriculumCatalog $catalog,
        private readonly PrimaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createProfile(Principal $principal, string $establishmentId, array $input): array
    {
        $this->guard($principal, $establishmentId);
        $t = $principal->tenantId;
        $levelId = isset($input['level_structure_id']) ? (string) $input['level_structure_id'] : null;
        $name = isset($input['name']) ? (string) $input['name'] : null;
        if (($levelId === null) === ($name === null)) {
            throw HttpException::validation(['level_structure_id' => ['Indiquez soit un niveau existant (level_structure_id), soit le nom d\'un niveau à créer (name).']]);
        }
        $section = isset($input['section_org_unit_id']) ? (string) $input['section_org_unit_id'] : null;
        $this->assertSection($t, $establishmentId, $section);
        $final = (bool) ($input['is_final_level'] ?? false);
        $this->assertSingleFinal($t, $establishmentId, $section, $final, null);

        if ($levelId !== null) {
            $level = $this->tree->structure($t, $establishmentId, $levelId);
            if ($level === null || $level->kind !== 'level') {
                throw HttpException::validation(['level_structure_id' => ['Niveau introuvable dans cet établissement.']]);
            }
        } else {
            $levelId = $this->structures->createStructure($t, $establishmentId, new StructureDraft(StructureDraft::LEVEL, (string) $name, $section), $principal->userId);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE_PROFILES, $t, $establishmentId, [
            'id' => $id, 'level_structure_id' => $levelId, 'section_org_unit_id' => $section,
            'cycle_code' => $input['cycle_code'] ?? null, 'sort_order' => (int) ($input['sort_order'] ?? 0),
            'is_final_level' => $final ? 1 : 0, 'is_exam_level' => (bool) ($input['is_exam_level'] ?? false) ? 1 : 0,
            'language' => (string) ($input['language'] ?? 'fr'), 'external_key' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail(self::TABLE_PROFILES, $t, $establishmentId, $id);
        $this->access->audit($principal, 'primary.level_profile.created', 'pri_level_profile', $id, null, ['level_structure_id' => $levelId]);

        return $row;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateProfile(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->guard($principal, $establishmentId);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::TABLE_PROFILES, $t, $establishmentId, $id, 'Profil de niveau introuvable.');
        $section = array_key_exists('section_org_unit_id', $changes) ? ($changes['section_org_unit_id'] === null ? null : (string) $changes['section_org_unit_id']) : ($row['section_org_unit_id'] === null ? null : (string) $row['section_org_unit_id']);
        $final = array_key_exists('is_final_level', $changes) ? (bool) $changes['is_final_level'] : (int) $row['is_final_level'] === 1;
        if (array_key_exists('section_org_unit_id', $changes)) {
            $this->assertSection($t, $establishmentId, $section);
        }
        $this->assertSingleFinal($t, $establishmentId, $section, $final, $id);
        $values = $changes;
        foreach (['is_final_level', 'is_exam_level'] as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = $values[$flag] ? 1 : 0;
            }
        }
        $values['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE_PROFILES, $t, $establishmentId, $values, ['id' => $id]);
        $after = $this->store->findOrFail(self::TABLE_PROFILES, $t, $establishmentId, $id);
        $this->access->audit($principal, 'primary.level_profile.updated', 'pri_level_profile', $id, ['is_final_level' => (int) $row['is_final_level']], ['is_final_level' => (int) $after['is_final_level']]);

        return $after;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createDomain(Principal $principal, string $establishmentId, array $input): array
    {
        $this->guard($principal, $establishmentId);
        $t = $principal->tenantId;
        $year = (string) $input['academic_year_id'];
        if ($this->store->select(self::TABLE_DOMAINS, $t, $establishmentId, ['academic_year_id' => $year, 'code' => $input['code']], 'id', 1) !== []) {
            throw HttpException::validation(['code' => ['Ce code de domaine existe déjà pour cette année.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE_DOMAINS, $t, $establishmentId, [
            'id' => $id, 'academic_year_id' => $year, 'code' => $input['code'], 'label_fr' => $input['label_fr'], 'label_en' => $input['label_en'] ?? null,
            'sort_order' => (int) ($input['sort_order'] ?? 0), 'external_key' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.domain.created', 'pri_domain', $id, null, ['code' => $input['code']]);

        return $this->store->findOrFail(self::TABLE_DOMAINS, $t, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateDomain(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->guard($principal, $establishmentId);

        return $this->patch($principal, $establishmentId, self::TABLE_DOMAINS, $id, $changes, 'Domaine introuvable.', 'primary.domain.updated');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createLevelSubject(Principal $principal, string $establishmentId, array $input): array
    {
        $this->guard($principal, $establishmentId);
        $t = $principal->tenantId;
        $year = (string) $input['academic_year_id'];
        $level = (string) $input['level_structure_id'];
        $this->assertProfile($t, $establishmentId, $level);
        $domain = $this->domainOfYear($t, $establishmentId, (string) $input['domain_id'], $year);
        $code = (string) $input['subject_code'];
        $subjectId = $this->upsertSubject($t, $establishmentId, $code, (string) $input['subject_name']);
        if ($this->store->select(self::TABLE_SUBJECTS, $t, $establishmentId, ['academic_year_id' => $year, 'level_structure_id' => $level, 'subject_id' => $subjectId], 'id', 1) !== []) {
            throw HttpException::validation(['subject_code' => ['Cette matière est déjà rattachée à ce niveau pour cette année.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE_SUBJECTS, $t, $establishmentId, [
            'id' => $id, 'academic_year_id' => $year, 'level_structure_id' => $level, 'subject_id' => $subjectId, 'subject_code' => $code,
            'subject_name' => (string) $input['subject_name'], 'domain_id' => (string) $domain['id'], 'evaluation_mode' => (string) ($input['evaluation_mode'] ?? EvaluationMode::BOTH),
            'sort_order' => (int) ($input['sort_order'] ?? 0), 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.level_subject.created', 'pri_level_subject', $id, null, ['level_structure_id' => $level, 'subject_code' => $code]);

        return $this->store->findOrFail(self::TABLE_SUBJECTS, $t, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateLevelSubject(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->guard($principal, $establishmentId);
        $row = $this->store->findOrFail(self::TABLE_SUBJECTS, $principal->tenantId, $establishmentId, $id, 'Matière du niveau introuvable.');
        if (isset($changes['domain_id'])) {
            $this->domainOfYear($principal->tenantId, $establishmentId, (string) $changes['domain_id'], (string) $row['academic_year_id']);
        }

        return $this->patch($principal, $establishmentId, self::TABLE_SUBJECTS, $id, $changes, 'Matière du niveau introuvable.', 'primary.level_subject.updated');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCompetency(Principal $principal, string $establishmentId, array $input): array
    {
        $this->guard($principal, $establishmentId);
        $t = $principal->tenantId;
        $year = (string) $input['academic_year_id'];
        $level = (string) $input['level_structure_id'];
        $this->assertProfile($t, $establishmentId, $level);
        $domain = $this->domainOfYear($t, $establishmentId, (string) $input['domain_id'], $year);
        $subjectId = null;
        if (isset($input['subject_id'])) {
            $subject = $this->store->select(self::TABLE_SUBJECTS, $t, $establishmentId, ['academic_year_id' => $year, 'level_structure_id' => $level, 'subject_id' => (string) $input['subject_id']], 'id', 1)[0] ?? null;
            if ($subject === null) {
                throw HttpException::validation(['subject_id' => ['Cette matière n\'est pas rattachée à ce niveau pour cette année.']]);
            }
            $subjectId = (string) $subject['subject_id'];
        }
        if ($this->store->select(self::TABLE_COMPETENCIES, $t, $establishmentId, ['academic_year_id' => $year, 'level_structure_id' => $level, 'code' => $input['code']], 'id', 1) !== []) {
            throw HttpException::validation(['code' => ['Ce code de compétence existe déjà pour ce niveau et cette année.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE_COMPETENCIES, $t, $establishmentId, [
            'id' => $id, 'academic_year_id' => $year, 'domain_id' => (string) $domain['id'], 'subject_id' => $subjectId, 'level_structure_id' => $level,
            'code' => $input['code'], 'label_fr' => $input['label_fr'], 'label_en' => $input['label_en'] ?? null, 'sort_order' => (int) ($input['sort_order'] ?? 0),
            'external_key' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.competency.created', 'pri_competency', $id, null, ['code' => $input['code'], 'level_structure_id' => $level]);

        return $this->store->findOrFail(self::TABLE_COMPETENCIES, $t, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateCompetency(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->guard($principal, $establishmentId);
        $row = $this->store->findOrFail(self::TABLE_COMPETENCIES, $principal->tenantId, $establishmentId, $id, 'Compétence introuvable.');
        if (isset($changes['domain_id'])) {
            $this->domainOfYear($principal->tenantId, $establishmentId, (string) $changes['domain_id'], (string) $row['academic_year_id']);
        }

        return $this->patch($principal, $establishmentId, self::TABLE_COMPETENCIES, $id, $changes, 'Compétence introuvable.', 'primary.competency.updated');
    }

    /** Matière du socle à clé externe stable `pri:<code>` ; nom et coefficient déjà saisis dans le socle sont conservés. */
    public function upsertSubject(string $tenantId, string $establishmentId, string $code, string $name): string
    {
        $key = self::SUBJECT_KEY_PREFIX . $code;
        $existing = $this->catalog->find($tenantId, $establishmentId, $key);

        return $this->catalog->upsertSubject($tenantId, $establishmentId, new CurriculumItem(
            $key,
            $code,
            $existing === null ? $name : $existing->name,
            $existing === null ? 'subject' : $existing->kind,
            $existing === null ? 1.0 : $existing->coefficient,
            0,
        ));
    }

    private function guard(Principal $principal, string $establishmentId): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'pri_referential');
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function patch(Principal $principal, string $establishmentId, string $table, string $id, array $changes, string $missing, string $action): array
    {
        $t = $principal->tenantId;
        $before = $this->store->findOrFail($table, $t, $establishmentId, $id, $missing);
        $values = $changes;
        if (array_key_exists('active', $values)) {
            $values['active'] = $values['active'] ? 1 : 0;
        }
        $values['updated_at'] = $this->clock->now();
        $this->store->update($table, $t, $establishmentId, $values, ['id' => $id]);
        $after = $this->store->findOrFail($table, $t, $establishmentId, $id);
        $this->access->audit($principal, $action, $table, $id, ['active' => (int) ($before['active'] ?? 1)], ['active' => (int) ($after['active'] ?? 1)]);

        return $after;
    }

    private function assertSection(string $tenantId, string $establishmentId, ?string $section): void
    {
        if ($section !== null && $this->tree->descendantsOf($tenantId, $establishmentId, $section) === []) {
            throw HttpException::validation(['section_org_unit_id' => ['Section introuvable dans cet établissement.']]);
        }
    }

    /** Un seul niveau final par section (RG-PRI-18). */
    private function assertSingleFinal(string $tenantId, string $establishmentId, ?string $section, bool $final, ?string $selfId): void
    {
        if (!$final) {
            return;
        }
        foreach ($this->store->select(self::TABLE_PROFILES, $tenantId, $establishmentId, ['section_org_unit_id' => $section, 'is_final_level' => 1]) as $other) {
            if ((string) $other['id'] !== $selfId) {
                throw HttpException::validation(['is_final_level' => ['Cette section a déjà un niveau final : un seul est permis.']]);
            }
        }
    }

    private function assertProfile(string $tenantId, string $establishmentId, string $levelId): void
    {
        if ($this->store->select(self::TABLE_PROFILES, $tenantId, $establishmentId, ['level_structure_id' => $levelId], 'id', 1) === []) {
            throw HttpException::validation(['level_structure_id' => ['Ce niveau n\'a pas de profil primaire : créez d\'abord son profil.']]);
        }
    }

    /** @return array<string, mixed> */
    private function domainOfYear(string $tenantId, string $establishmentId, string $domainId, string $year): array
    {
        $domain = $this->store->find(self::TABLE_DOMAINS, $tenantId, $establishmentId, $domainId);
        if ($domain === null || (string) $domain['academic_year_id'] !== $year) {
            throw HttpException::validation(['domain_id' => ['Domaine introuvable pour cette année.']]);
        }

        return $domain;
    }
}
