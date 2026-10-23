<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/**
 * Modèles de référentiel (EX-PRI-05, 17) : application d'un modèle à une année (niveaux, classes, matières, domaines, compétences),
 * IDEMPOTENTE (clé externe `tpl:<modèle>:<niveau>` sur le profil de niveau, upsert par code), et copie d'une année à l'autre.
 * Les modèles de la plateforme (client « platform ») sont lisibles par tous ; le modèle `example` est étiqueté exemple.
 */
final class TemplateService
{
    public const TABLE = 'pri_referential_templates';
    public const PLATFORM = 'platform';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly OrgUnitTree $tree,
        private readonly StructureWriter $structures,
        private readonly ReferentialService $referential,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> modèles de la plateforme puis modèles propres à l'établissement */
    public function all(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, Permissions::READ);

        return array_merge(
            $this->store->select(self::TABLE, self::PLATFORM, self::PLATFORM, [], 'code'),
            $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, [], 'code'),
        );
    }

    /** @return array<string, mixed> */
    public function find(Principal $principal, string $establishmentId, string $code): array
    {
        $this->access->require($principal, $establishmentId, Permissions::READ);

        return $this->template($principal->tenantId, $establishmentId, $code);
    }

    /**
     * @return array{academic_year_id: string, template: string, origin: string, created: array<string, int>, existing: array<string, int>}
     */
    public function apply(Principal $principal, string $establishmentId, string $code, string $academicYearId, ?string $sectionOrgUnitId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'pri_referential');
        $t = $principal->tenantId;
        $row = $this->template($t, $establishmentId, $code);
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            throw HttpException::validation(['code' => ['Modèle illisible.']]);
        }
        if ($sectionOrgUnitId !== null && $this->tree->descendantsOf($t, $establishmentId, $sectionOrgUnitId) === []) {
            throw HttpException::validation(['section_org_unit_id' => ['Section introuvable dans cet établissement.']]);
        }

        $created = ['domains' => 0, 'levels' => 0, 'classes' => 0, 'subjects' => 0, 'competencies' => 0];
        $existing = ['domains' => 0, 'levels' => 0, 'subjects' => 0, 'competencies' => 0];

        $this->store->transaction(function () use ($principal, $establishmentId, $code, $academicYearId, $sectionOrgUnitId, $row, $payload, &$created, &$existing): void {
            $t = $principal->tenantId;
            $domains = [];
            foreach ((array) ($payload['domains'] ?? []) as $domain) {
                $domain = (array) $domain;
                $found = $this->store->select(ReferentialService::TABLE_DOMAINS, $t, $establishmentId, ['academic_year_id' => $academicYearId, 'code' => (string) $domain['code']], 'id', 1)[0] ?? null;
                if ($found === null) {
                    $found = $this->insertDomain($t, $establishmentId, $academicYearId, $domain);
                    ++$created['domains'];
                } else {
                    ++$existing['domains'];
                }
                $domains[(string) $domain['code']] = (string) $found['id'];
            }
            foreach ((array) ($payload['levels'] ?? []) as $level) {
                $level = (array) $level;
                $key = 'tpl:' . $code . ':' . (string) $level['code'];
                $profile = $this->store->select(ReferentialService::TABLE_PROFILES, $t, $establishmentId, ['external_key' => $key], 'id', 1)[0] ?? null;
                if ($profile === null) {
                    $profile = $this->createLevel($principal, $establishmentId, $level, $key, (string) $row['language'], $sectionOrgUnitId, $created);
                    ++$created['levels'];
                } else {
                    ++$existing['levels'];
                }
                $levelId = (string) $profile['level_structure_id'];
                $subjects = [];
                foreach ((array) ($level['subjects'] ?? []) as $subject) {
                    $subject = (array) $subject;
                    $subjectId = $this->referential->upsertSubject($t, $establishmentId, (string) $subject['code'], (string) $subject['name']);
                    $subjects[(string) $subject['code']] = $subjectId;
                    $domainId = $domains[(string) $subject['domain']] ?? throw HttpException::validation(['code' => ['Domaine inconnu dans le modèle : ' . (string) $subject['domain'] . '.']]);
                    $found = $this->store->select(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, ['academic_year_id' => $academicYearId, 'level_structure_id' => $levelId, 'subject_id' => $subjectId], 'id', 1)[0] ?? null;
                    if ($found !== null) {
                        ++$existing['subjects'];
                        continue;
                    }
                    $now = $this->clock->now();
                    $this->store->insert(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, [
                        'id' => (string) new Ulid(), 'academic_year_id' => $academicYearId, 'level_structure_id' => $levelId, 'subject_id' => $subjectId,
                        'subject_code' => (string) $subject['code'], 'subject_name' => (string) $subject['name'], 'domain_id' => $domainId,
                        'evaluation_mode' => (string) ($subject['evaluation_mode'] ?? 'both'), 'sort_order' => (int) ($subject['sort_order'] ?? 0),
                        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    ++$created['subjects'];
                }
                foreach ((array) ($level['competencies'] ?? []) as $competency) {
                    $competency = (array) $competency;
                    $found = $this->store->select(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, ['academic_year_id' => $academicYearId, 'level_structure_id' => $levelId, 'code' => (string) $competency['code']], 'id', 1)[0] ?? null;
                    if ($found !== null) {
                        ++$existing['competencies'];
                        continue;
                    }
                    $domainId = $domains[(string) $competency['domain']] ?? throw HttpException::validation(['code' => ['Domaine inconnu dans le modèle : ' . (string) $competency['domain'] . '.']]);
                    $now = $this->clock->now();
                    $this->store->insert(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, [
                        'id' => (string) new Ulid(), 'academic_year_id' => $academicYearId, 'domain_id' => $domainId,
                        'subject_id' => isset($competency['subject']) ? ($subjects[(string) $competency['subject']] ?? null) : null, 'level_structure_id' => $levelId,
                        'code' => (string) $competency['code'], 'label_fr' => (string) $competency['label_fr'], 'label_en' => isset($competency['label_en']) ? (string) $competency['label_en'] : null,
                        'sort_order' => (int) ($competency['sort_order'] ?? 0), 'external_key' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    ++$created['competencies'];
                }
            }
        });
        $this->access->audit($principal, 'primary.referential.applied', 'pri_referential_template', $code, null, ['academic_year_id' => $academicYearId] + $created);

        return ['academic_year_id' => $academicYearId, 'template' => $code, 'origin' => (string) $row['origin'], 'created' => $created, 'existing' => $existing];
    }

    /**
     * Copie domaines, matières par niveau et compétences d'une année vers une autre ; ce qui existe déjà dans l'année cible est conservé.
     *
     * @return array{from: string, to: string, created: array<string, int>, existing: array<string, int>}
     */
    public function copyYear(Principal $principal, string $establishmentId, string $fromYear, string $toYear): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, Permissions::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'pri_referential');
        if ($fromYear === $toYear) {
            throw HttpException::validation(['to_year_id' => ['L\'année cible doit différer de l\'année source.']]);
        }
        $t = $principal->tenantId;
        $created = ['domains' => 0, 'subjects' => 0, 'competencies' => 0];
        $existing = ['domains' => 0, 'subjects' => 0, 'competencies' => 0];
        $this->store->transaction(function () use ($t, $establishmentId, $fromYear, $toYear, &$created, &$existing): void {
            $map = [];
            foreach ($this->store->select(ReferentialService::TABLE_DOMAINS, $t, $establishmentId, ['academic_year_id' => $fromYear], 'sort_order, code') as $domain) {
                $found = $this->store->select(ReferentialService::TABLE_DOMAINS, $t, $establishmentId, ['academic_year_id' => $toYear, 'code' => (string) $domain['code']], 'id', 1)[0] ?? null;
                if ($found === null) {
                    $found = $this->insertDomain($t, $establishmentId, $toYear, $domain);
                    ++$created['domains'];
                } else {
                    ++$existing['domains'];
                }
                $map[(string) $domain['id']] = (string) $found['id'];
            }
            foreach ($this->store->select(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, ['academic_year_id' => $fromYear], 'level_structure_id, sort_order') as $subject) {
                $found = $this->store->select(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, ['academic_year_id' => $toYear, 'level_structure_id' => (string) $subject['level_structure_id'], 'subject_id' => (string) $subject['subject_id']], 'id', 1)[0] ?? null;
                if ($found !== null) {
                    ++$existing['subjects'];
                    continue;
                }
                $now = $this->clock->now();
                $this->store->insert(ReferentialService::TABLE_SUBJECTS, $t, $establishmentId, [
                    'id' => (string) new Ulid(), 'academic_year_id' => $toYear, 'level_structure_id' => (string) $subject['level_structure_id'], 'subject_id' => (string) $subject['subject_id'],
                    'subject_code' => (string) $subject['subject_code'], 'subject_name' => (string) $subject['subject_name'], 'domain_id' => $map[(string) $subject['domain_id']] ?? (string) $subject['domain_id'],
                    'evaluation_mode' => (string) $subject['evaluation_mode'], 'sort_order' => (int) $subject['sort_order'], 'active' => (int) $subject['active'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                ++$created['subjects'];
            }
            foreach ($this->store->select(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, ['academic_year_id' => $fromYear], 'level_structure_id, sort_order') as $competency) {
                $found = $this->store->select(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, ['academic_year_id' => $toYear, 'level_structure_id' => (string) $competency['level_structure_id'], 'code' => (string) $competency['code']], 'id', 1)[0] ?? null;
                if ($found !== null) {
                    ++$existing['competencies'];
                    continue;
                }
                $now = $this->clock->now();
                $this->store->insert(ReferentialService::TABLE_COMPETENCIES, $t, $establishmentId, [
                    'id' => (string) new Ulid(), 'academic_year_id' => $toYear, 'domain_id' => $map[(string) $competency['domain_id']] ?? (string) $competency['domain_id'],
                    'subject_id' => $competency['subject_id'] === null ? null : (string) $competency['subject_id'], 'level_structure_id' => (string) $competency['level_structure_id'],
                    'code' => (string) $competency['code'], 'label_fr' => (string) $competency['label_fr'], 'label_en' => $competency['label_en'] === null ? null : (string) $competency['label_en'],
                    'sort_order' => (int) $competency['sort_order'], 'external_key' => null, 'active' => (int) $competency['active'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                ++$created['competencies'];
            }
        });
        $this->access->audit($principal, 'primary.referential.year_copied', 'pri_referential', $toYear, null, ['from' => $fromYear] + $created);

        return ['from' => $fromYear, 'to' => $toYear, 'created' => $created, 'existing' => $existing];
    }

    /** @return array<string, mixed> */
    private function template(string $tenantId, string $establishmentId, string $code): array
    {
        $own = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['code' => $code], 'id', 1)[0] ?? null;

        return $own ?? $this->store->select(self::TABLE, self::PLATFORM, self::PLATFORM, ['code' => $code], 'id', 1)[0]
            ?? throw HttpException::notFound('Modèle de référentiel introuvable.');
    }

    /**
     * @param array<string, mixed> $domain
     * @return array<string, mixed>
     */
    private function insertDomain(string $tenantId, string $establishmentId, string $year, array $domain): array
    {
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(ReferentialService::TABLE_DOMAINS, $tenantId, $establishmentId, [
            'id' => $id, 'academic_year_id' => $year, 'code' => (string) $domain['code'], 'label_fr' => (string) $domain['label_fr'],
            'label_en' => isset($domain['label_en']) ? (string) $domain['label_en'] : null, 'sort_order' => (int) ($domain['sort_order'] ?? 0),
            'external_key' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->store->findOrFail(ReferentialService::TABLE_DOMAINS, $tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $level
     * @param array<string, int> $created
     * @return array<string, mixed>
     */
    private function createLevel(Principal $principal, string $establishmentId, array $level, string $key, string $language, ?string $section, array &$created): array
    {
        $t = $principal->tenantId;
        $levelId = $this->structures->createStructure($t, $establishmentId, new StructureDraft(StructureDraft::LEVEL, (string) $level['name'], $section), $principal->userId);
        foreach ((array) ($level['classes'] ?? []) as $className) {
            $this->structures->createStructure($t, $establishmentId, new StructureDraft(StructureDraft::SCHOOL_CLASS, (string) $className, $section, $levelId), $principal->userId);
            ++$created['classes'];
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(ReferentialService::TABLE_PROFILES, $t, $establishmentId, [
            'id' => $id, 'level_structure_id' => $levelId, 'section_org_unit_id' => $section, 'cycle_code' => isset($level['cycle_code']) ? (string) $level['cycle_code'] : null,
            'sort_order' => (int) ($level['sort_order'] ?? 0), 'is_final_level' => (bool) ($level['is_final_level'] ?? false) ? 1 : 0,
            'is_exam_level' => (bool) ($level['is_exam_level'] ?? false) ? 1 : 0, 'language' => $language, 'external_key' => $key, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->store->findOrFail(ReferentialService::TABLE_PROFILES, $t, $establishmentId, $id);
    }
}
