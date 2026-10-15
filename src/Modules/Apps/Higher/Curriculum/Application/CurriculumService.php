<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Domain\CoherenceChecker;
use Scholaris\Modules\Apps\Higher\Curriculum\Domain\CurriculumStatus;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Maquettes (MAQ, T215, T220-T222) : par filière, niveau et année ; semestres, UE (types, UE à choix), EC (crédits, coefficient,
 * volumes CM/TD/TP/travail personnel) ; BTS/HND en matières et coefficients rattachées aux épreuves de l'examen national.
 *
 *  - Brouillon seul modifiable. Publication (validation, higher.curriculum.approve) après contrôle de cohérence (RG-MAQ-2,
 *    UE sans EC, volumes, RG-MAQ-3), projection vers le référentiel d'évaluation (D9) et instantané.
 *  - RG-MAQ-1 : une version publiée n'est jamais modifiée sur place. Sans notes, elle peut être dépubliée (retour au brouillon) ;
 *    avec des notes, seule une NOUVELLE VERSION est possible ; à sa publication, l'ancienne passe « remplacée » et les étudiants
 *    inscrits sur elle y restent (l'inscription mémorise l'identifiant de version).
 *  - Copie d'une année à l'autre (EX-MAQ-05).
 */
final class CurriculumService
{
    public const MANAGE = 'higher.curriculum.manage';
    public const APPROVE = 'higher.curriculum.approve';
    public const READ = ['higher.curriculum.read', 'higher.curriculum.manage', 'higher.curriculum.approve'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicClock $clock,
        private readonly AcademicDirectory $academic,
        private readonly OrgUnitSettingsReader $settings,
        private readonly CatalogProjector $projector,
        private readonly GradeProbes $probes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    public static function scope(array $row): array
    {
        return [
            'school' => (string) $row['school_id'], 'department' => (string) $row['department_id'],
            'program' => (string) $row['program_id'], 'level' => (string) $row['level_id'],
        ];
    }

    /**
     * @param array<string, mixed> $v program_id, level_id, academic_year_id, title, approved_programme_ref, semester_ordinals
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [self::MANAGE]);
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $v['program_id'])
            ?? throw HttpException::validation(['program_id' => ['Filière introuvable dans cet établissement.']]);
        $level = $this->store->find('hi_levels', $tenantId, $establishmentId, (string) $v['level_id']);
        if ($level === null || $level['program_id'] !== $program['id']) {
            throw HttpException::validation(['level_id' => ['Niveau introuvable dans cette filière.']]);
        }
        $this->access->assertWritable($principal, $perimeter, self::scope(['program_id' => $program['id'], 'level_id' => $level['id']] + $program), 'higher_curriculum', (string) $program['id']);
        $key = ['program_id' => (string) $program['id'], 'level_id' => (string) $level['id'], 'academic_year_id' => (string) $v['academic_year_id']];
        $this->assertNoLiveVersion($tenantId, $establishmentId, $key);
        $ordinal = (int) $level['ordinal'];
        $semesters = $v['semester_ordinals'] ?? [2 * $ordinal - 1, 2 * $ordinal];
        $title = $v['title'] ?? ($program['name'] . ' - ' . $level['name']);

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $program, $key, $semesters, $title, $v): array {
            $id = $this->insertCurriculum($principal, $tenantId, $establishmentId, $program, $key, $this->nextVersion($tenantId, $establishmentId, $key), (string) $title, $v['approved_programme_ref'] ?? null, null, null);
            foreach ($semesters as $ordinal) {
                $this->store->insert('hi_curriculum_semesters', $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'curriculum_id' => $id, 'ordinal' => (int) $ordinal, 'label' => 'S' . (int) $ordinal, 'created_at' => $this->clock->now(),
                ]);
            }
            $row = $this->store->findOrFail('hi_curricula', $tenantId, $establishmentId, $id);
            $this->access->audit($principal, 'higher.curriculum.created', 'higher_curriculum', $id, null, $row);

            return $row;
        });
    }

    /**
     * @param array<string, mixed> $v title, approved_programme_ref
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $row = $this->editable($principal, $establishmentId, $id);
        /** @var array<string, scalar|null> $changes */
        $changes = array_intersect_key($v, ['title' => true, 'approved_programme_ref' => true]);
        if ($changes !== []) {
            $this->store->update('hi_curricula', $principal->tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
            $this->access->audit($principal, 'higher.curriculum.updated', 'higher_curriculum', $id, $row, $changes);
        }

        return $this->store->findOrFail('hi_curricula', $principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function addUnit(Principal $principal, string $establishmentId, string $curriculumId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->editable($principal, $establishmentId, $curriculumId);
        $semester = $this->store->select('hi_curriculum_semesters', $tenantId, $establishmentId, ['curriculum_id' => $curriculumId, 'ordinal' => (int) $v['semester_ordinal']], 'id', 1)[0]
            ?? throw HttpException::validation(['semester_ordinal' => ['Semestre absent de la maquette.']]);
        $this->assertFreeCode('hi_curriculum_units', $tenantId, $establishmentId, $curriculumId, (string) $v['code']);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_curriculum_units', $tenantId, $establishmentId, [
            'id' => $id, 'curriculum_id' => $curriculumId, 'semester_id' => $semester['id'], 'code' => $v['code'], 'title' => $v['title'],
            'unit_type' => $v['unit_type'], 'credits' => $v['credits'] ?? 0, 'coefficient' => $v['coefficient'] ?? 1.0,
            'choice_group' => $v['choice_group'] ?? null, 'choice_count' => $v['choice_count'] ?? null,
            'national_exam_paper' => $v['national_exam_paper'] ?? null, 'cc_weight_percent' => $v['cc_weight_percent'] ?? null,
            'sort_index' => $v['sort_index'] ?? $this->store->count('hi_curriculum_units', $tenantId, $establishmentId, ['curriculum_id' => $curriculumId]) + 1,
            'catalog_subject_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.unit_added', ['unit_id' => $id, 'code' => $v['code']]);

        return $this->store->findOrFail('hi_curriculum_units', $tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function updateUnit(Principal $principal, string $establishmentId, string $unitId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $unit = $this->store->findOrFail('hi_curriculum_units', $tenantId, $establishmentId, $unitId, 'UE introuvable.');
        $row = $this->editable($principal, $establishmentId, (string) $unit['curriculum_id']);
        if (isset($v['code']) && $v['code'] !== $unit['code']) {
            $this->assertFreeCode('hi_curriculum_units', $tenantId, $establishmentId, (string) $row['id'], (string) $v['code']);
        }
        if (isset($v['semester_ordinal'])) {
            $semester = $this->store->select('hi_curriculum_semesters', $tenantId, $establishmentId, ['curriculum_id' => $row['id'], 'ordinal' => (int) $v['semester_ordinal']], 'id', 1)[0]
                ?? throw HttpException::validation(['semester_ordinal' => ['Semestre absent de la maquette.']]);
            $v['semester_id'] = $semester['id'];
            unset($v['semester_ordinal']);
        }
        /** @var array<string, scalar|null> $changes */
        $changes = array_filter($v, static fn (mixed $value): bool => is_scalar($value) || $value === null);
        $this->store->update('hi_curriculum_units', $tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $unitId]);
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.unit_updated', ['unit_id' => $unitId] + $changes);

        return $this->store->findOrFail('hi_curriculum_units', $tenantId, $establishmentId, $unitId);
    }

    public function removeUnit(Principal $principal, string $establishmentId, string $unitId): void
    {
        $tenantId = $principal->tenantId;
        $unit = $this->store->findOrFail('hi_curriculum_units', $tenantId, $establishmentId, $unitId, 'UE introuvable.');
        $row = $this->editable($principal, $establishmentId, (string) $unit['curriculum_id']);
        $this->store->transaction(function () use ($tenantId, $establishmentId, $unitId): void {
            $this->store->delete('hi_curriculum_elements', $tenantId, $establishmentId, ['unit_id' => $unitId]);
            $this->store->delete('hi_curriculum_units', $tenantId, $establishmentId, ['id' => $unitId]);
        });
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.unit_removed', ['unit_id' => $unitId, 'code' => $unit['code']]);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function addElement(Principal $principal, string $establishmentId, string $unitId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $unit = $this->store->findOrFail('hi_curriculum_units', $tenantId, $establishmentId, $unitId, 'UE introuvable.');
        $row = $this->editable($principal, $establishmentId, (string) $unit['curriculum_id']);
        $this->assertFreeCode('hi_curriculum_elements', $tenantId, $establishmentId, (string) $row['id'], (string) $v['code']);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_curriculum_elements', $tenantId, $establishmentId, self::volumes($v) + [
            'id' => $id, 'curriculum_id' => $row['id'], 'unit_id' => $unitId, 'code' => $v['code'], 'title' => $v['title'],
            'credits' => $v['credits'] ?? 0, 'coefficient' => $v['coefficient'] ?? 1.0, 'cc_weight_percent' => $v['cc_weight_percent'] ?? null,
            'cm_minutes' => 0, 'td_minutes' => 0, 'tp_minutes' => 0, 'personal_minutes' => 0,
            'sort_index' => $v['sort_index'] ?? $this->store->count('hi_curriculum_elements', $tenantId, $establishmentId, ['unit_id' => $unitId]) + 1,
            'catalog_element_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.element_added', ['element_id' => $id, 'code' => $v['code']]);

        return $this->store->findOrFail('hi_curriculum_elements', $tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function updateElement(Principal $principal, string $establishmentId, string $elementId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $element = $this->store->findOrFail('hi_curriculum_elements', $tenantId, $establishmentId, $elementId, 'EC introuvable.');
        $row = $this->editable($principal, $establishmentId, (string) $element['curriculum_id']);
        if (isset($v['code']) && $v['code'] !== $element['code']) {
            $this->assertFreeCode('hi_curriculum_elements', $tenantId, $establishmentId, (string) $row['id'], (string) $v['code']);
        }
        /** @var array<string, scalar|null> $changes */
        $changes = self::volumes($v) + array_filter(
            array_intersect_key($v, ['code' => true, 'title' => true, 'credits' => true, 'coefficient' => true, 'cc_weight_percent' => true, 'sort_index' => true]),
            static fn (mixed $value): bool => is_scalar($value) || $value === null,
        );
        $this->store->update('hi_curriculum_elements', $tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $elementId]);
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.element_updated', ['element_id' => $elementId] + $changes);

        return $this->store->findOrFail('hi_curriculum_elements', $tenantId, $establishmentId, $elementId);
    }

    public function removeElement(Principal $principal, string $establishmentId, string $elementId): void
    {
        $tenantId = $principal->tenantId;
        $element = $this->store->findOrFail('hi_curriculum_elements', $tenantId, $establishmentId, $elementId, 'EC introuvable.');
        $row = $this->editable($principal, $establishmentId, (string) $element['curriculum_id']);
        $this->store->delete('hi_curriculum_elements', $tenantId, $establishmentId, ['id' => $elementId]);
        $this->touch($principal, $establishmentId, $row, 'higher.curriculum.element_removed', ['element_id' => $elementId, 'code' => $element['code']]);
    }

    /**
     * Contrôle de cohérence (EX-MAQ-06) : erreurs bloquantes, avertissements, crédits et volumes par semestre.
     *
     * @param array<string, mixed> $row
     * @return array{errors: list<string>, warnings: list<string>, semesters: list<array{ordinal: int, label: string, credits: int, volume_minutes: int}>}
     */
    public function check(string $tenantId, string $establishmentId, array $row): array
    {
        [$semesters, $units, $elements] = $this->content($tenantId, $establishmentId, (string) $row['id']);
        $school = (string) $row['school_id'];

        return CoherenceChecker::check(
            (string) $row['regime'],
            (int) $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, 'higher.curriculum.credits_per_semester', 30),
            (int) $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, 'higher.curriculum.hours_per_credit', 25),
            $semesters,
            $units,
            $elements,
            $row['approved_programme_ref'] === null ? null : (string) $row['approved_programme_ref'],
            $this->academic->tutelageFor($tenantId, $establishmentId, (string) $row['program_id'], (string) $row['academic_year_id']) !== null,
        );
    }

    /** @return array<string, mixed> */
    public function publish(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, self::APPROVE);
        if ($row['status'] !== CurriculumStatus::Draft->value) {
            throw HttpException::conflict('Seule une maquette en brouillon se publie.');
        }
        $check = $this->check($tenantId, $establishmentId, $row);
        if ($check['errors'] !== []) {
            throw HttpException::validation(['curriculum' => $check['errors']], 'Maquette incohérente : publication refusée.');
        }

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $id, $row): array {
            $now = $this->clock->now();
            foreach ($this->store->select('hi_curricula', $tenantId, $establishmentId, ['program_id' => $row['program_id'], 'level_id' => $row['level_id'], 'academic_year_id' => $row['academic_year_id'], 'status' => CurriculumStatus::Published->value]) as $other) {
                $this->store->update('hi_curricula', $tenantId, $establishmentId, ['status' => CurriculumStatus::Superseded->value, 'updated_at' => $now], ['id' => (string) $other['id']]);
                $this->access->audit($principal, 'higher.curriculum.superseded', 'higher_curriculum', (string) $other['id'], ['status' => 'published'], ['status' => 'superseded', 'by' => $id]);
            }
            $this->store->update('hi_curricula', $tenantId, $establishmentId, ['status' => CurriculumStatus::Published->value, 'published_at' => $now, 'published_by' => $principal->userId, 'updated_at' => $now], ['id' => $id]);
            [, $units, $elements] = $this->content($tenantId, $establishmentId, $id);
            [$subjects, $items] = $this->projector->project($tenantId, $establishmentId, $id, $units, $elements);
            foreach ($subjects as $unitId => $subjectId) {
                $this->store->update('hi_curriculum_units', $tenantId, $establishmentId, ['catalog_subject_id' => $subjectId], ['id' => $unitId]);
            }
            foreach ($items as $elementId => $catalogId) {
                $this->store->update('hi_curriculum_elements', $tenantId, $establishmentId, ['catalog_element_id' => $catalogId], ['id' => $elementId]);
            }
            $published = $this->store->findOrFail('hi_curricula', $tenantId, $establishmentId, $id);
            $this->store->insert('hi_curriculum_snapshots', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'curriculum_id' => $id, 'version' => (int) $published['version'],
                'content' => json_encode($this->tree($tenantId, $establishmentId, $published), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'published_by' => $principal->userId, 'created_at' => $now,
            ]);
            $this->access->audit($principal, 'higher.curriculum.published', 'higher_curriculum', $id, ['status' => $row['status']], ['status' => 'published', 'version' => (int) $published['version']]);

            return $published;
        });
    }

    /**
     * Retour au brouillon d'une version publiée SANS notes (RG-MAQ-1) ; la version remplacée redevient en vigueur.
     *
     * @return array<string, mixed>
     */
    public function unpublish(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, self::APPROVE);
        if ($row['status'] !== CurriculumStatus::Published->value) {
            throw HttpException::conflict('Seule une maquette publiée se dépublie.');
        }
        if ($this->hasGrades($tenantId, $establishmentId, $row)) {
            throw self::gradesConflict();
        }

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $id, $row): array {
            $now = $this->clock->now();
            [, $units, $elements] = $this->content($tenantId, $establishmentId, $id);
            $this->projector->withdraw($tenantId, $establishmentId, $units, $elements);
            $this->store->update('hi_curricula', $tenantId, $establishmentId, ['status' => CurriculumStatus::Draft->value, 'published_at' => null, 'published_by' => null, 'updated_at' => $now], ['id' => $id]);
            $previous = $row['previous_version_id'] === null ? null : $this->store->find('hi_curricula', $tenantId, $establishmentId, (string) $row['previous_version_id']);
            if ($previous !== null && $previous['status'] === CurriculumStatus::Superseded->value) {
                $this->store->update('hi_curricula', $tenantId, $establishmentId, ['status' => CurriculumStatus::Published->value, 'updated_at' => $now], ['id' => (string) $previous['id']]);
            }
            $this->access->audit($principal, 'higher.curriculum.unpublished', 'higher_curriculum', $id, ['status' => 'published'], ['status' => 'draft']);

            return $this->store->findOrFail('hi_curricula', $tenantId, $establishmentId, $id);
        });
    }

    /**
     * Nouvelle version (RG-MAQ-1) : brouillon copié d'une version publiée ou remplacée, même filière, niveau et année.
     *
     * @return array<string, mixed>
     */
    public function newVersion(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $source = $this->writable($principal, $establishmentId, $id, self::MANAGE);
        if ($source['status'] === CurriculumStatus::Draft->value) {
            throw HttpException::conflict('Cette maquette est un brouillon : modifiez-la directement.');
        }
        $key = ['program_id' => (string) $source['program_id'], 'level_id' => (string) $source['level_id'], 'academic_year_id' => (string) $source['academic_year_id']];
        if ($this->store->count('hi_curricula', $tenantId, $establishmentId, $key + ['status' => CurriculumStatus::Draft->value]) > 0) {
            throw HttpException::conflict('Une nouvelle version est déjà en brouillon pour cette filière, ce niveau et cette année.');
        }

        return $this->cloneCurriculum($principal, $establishmentId, $source, $key, (string) $source['id'], null, 'higher.curriculum.version_created');
    }

    /**
     * Copie vers une autre année (EX-MAQ-05) : nouveau brouillon, mêmes semestres, UE et EC.
     *
     * @return array<string, mixed>
     */
    public function copy(Principal $principal, string $establishmentId, string $id, string $academicYearId): array
    {
        $tenantId = $principal->tenantId;
        $source = $this->writable($principal, $establishmentId, $id, self::MANAGE);
        if ($academicYearId === $source['academic_year_id']) {
            throw HttpException::validation(['academic_year_id' => ['Choisissez une autre année : dans la même année, créez une nouvelle version.']]);
        }
        $key = ['program_id' => (string) $source['program_id'], 'level_id' => (string) $source['level_id'], 'academic_year_id' => $academicYearId];
        $this->assertNoLiveVersion($tenantId, $establishmentId, $key);

        return $this->cloneCurriculum($principal, $establishmentId, $source, $key, null, (string) $source['id'], 'higher.curriculum.copied');
    }

    /**
     * Maquette complète : semestres > UE > EC, totaux et contrôle.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function tree(string $tenantId, string $establishmentId, array $row): array
    {
        [$semesters, $units, $elements] = $this->content($tenantId, $establishmentId, (string) $row['id']);
        $byUnit = [];
        foreach ($elements as $element) {
            $byUnit[(string) $element['unit_id']][] = self::element($element);
        }
        $tree = [];
        foreach ($semesters as $semester) {
            $semesterUnits = [];
            foreach ($units as $unit) {
                if ((string) $unit['semester_id'] !== (string) $semester['id']) {
                    continue;
                }
                $unitElements = $byUnit[(string) $unit['id']] ?? [];
                $semesterUnits[] = self::unit($unit) + [
                    'volume_hours' => round(array_sum(array_map(static fn (array $e): float => $e['cm_hours'] + $e['td_hours'] + $e['tp_hours'], $unitElements)), 2),
                    'elements' => $unitElements,
                ];
            }
            $tree[] = ['id' => $semester['id'], 'ordinal' => (int) $semester['ordinal'], 'label' => $semester['label'], 'units' => $semesterUnits];
        }

        return self::curriculum($row) + ['semesters' => $tree];
    }

    /**
     * Lignes de l'export CSV/XLSX (une ligne par EC ; une matière sans EC donne une ligne).
     *
     * @param array<string, mixed> $row
     * @return list<list<mixed>>
     */
    public function exportRows(string $tenantId, string $establishmentId, array $row): array
    {
        $lines = [];
        foreach ($this->tree($tenantId, $establishmentId, $row)['semesters'] as $semester) {
            foreach ($semester['units'] as $unit) {
                $base = [$semester['label'], $unit['code'], $unit['title'], $unit['unit_type'], $unit['credits'], $unit['coefficient'], $unit['choice_group'], $unit['national_exam_paper']];
                if ($unit['elements'] === []) {
                    $lines[] = [...$base, '', '', '', '', '', '', '', ''];
                }
                foreach ($unit['elements'] as $e) {
                    $lines[] = [...$base, $e['code'], $e['title'], $e['credits'], $e['coefficient'], $e['cm_hours'], $e['td_hours'], $e['tp_hours'], $e['personal_hours']];
                }
            }
        }

        return $lines;
    }

    /** @return list<string> */
    public static function exportHeaders(): array
    {
        return [
            'Semestre', 'Code UE', 'Intitulé UE', 'Type UE', 'Crédits UE', 'Coefficient UE', 'Groupe à choix', 'Épreuve nationale',
            'Code EC', 'Intitulé EC', 'Crédits EC', 'Coefficient EC', 'CM (h)', 'TD (h)', 'TP (h)', 'Travail personnel (h)',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function curriculum(array $row): array
    {
        $data = [];
        foreach ([
            'id', 'school_id', 'department_id', 'program_id', 'level_id', 'academic_year_id', 'regime', 'version', 'status', 'title',
            'approved_programme_ref', 'previous_version_id', 'copied_from_id', 'published_at', 'published_by', 'grades_recorded_at', 'created_at', 'updated_at',
        ] as $field) {
            $data[$field] = $row[$field] ?? null;
        }
        $data['version'] = (int) ($row['version'] ?? 0);

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function unit(array $row): array
    {
        return [
            'id' => $row['id'], 'curriculum_id' => $row['curriculum_id'], 'semester_id' => $row['semester_id'], 'code' => $row['code'],
            'title' => $row['title'], 'unit_type' => $row['unit_type'], 'credits' => (int) $row['credits'], 'coefficient' => (float) $row['coefficient'],
            'choice_group' => $row['choice_group'], 'choice_count' => $row['choice_count'] === null ? null : (int) $row['choice_count'],
            'national_exam_paper' => $row['national_exam_paper'], 'cc_weight_percent' => $row['cc_weight_percent'] === null ? null : (int) $row['cc_weight_percent'],
            'sort_index' => (int) $row['sort_index'], 'catalog_subject_id' => $row['catalog_subject_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, unit_id: string, code: string, title: string, credits: int, coefficient: float, cm_hours: float, td_hours: float, tp_hours: float, personal_hours: float, cc_weight_percent: ?int, sort_index: int, catalog_element_id: ?string}
     */
    public static function element(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'unit_id' => (string) $row['unit_id'], 'code' => (string) $row['code'], 'title' => (string) $row['title'],
            'credits' => (int) $row['credits'], 'coefficient' => (float) $row['coefficient'],
            'cm_hours' => round((int) $row['cm_minutes'] / 60, 2), 'td_hours' => round((int) $row['td_minutes'] / 60, 2),
            'tp_hours' => round((int) $row['tp_minutes'] / 60, 2), 'personal_hours' => round((int) $row['personal_minutes'] / 60, 2),
            'cc_weight_percent' => $row['cc_weight_percent'] === null ? null : (int) $row['cc_weight_percent'],
            'sort_index' => (int) $row['sort_index'], 'catalog_element_id' => $row['catalog_element_id'] === null ? null : (string) $row['catalog_element_id'],
        ];
    }

    /** @param array<string, mixed> $row */
    public function hasGrades(string $tenantId, string $establishmentId, array $row): bool
    {
        return $row['grades_recorded_at'] !== null || $this->probes->anyGrades($tenantId, $establishmentId, (string) $row['id']);
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>} */
    public function content(string $tenantId, string $establishmentId, string $curriculumId): array
    {
        $semesters = $this->store->select('hi_curriculum_semesters', $tenantId, $establishmentId, ['curriculum_id' => $curriculumId], 'ordinal');
        $units = $this->store->select('hi_curriculum_units', $tenantId, $establishmentId, ['curriculum_id' => $curriculumId], 'sort_index, code', 5000);
        $elements = $this->store->select('hi_curriculum_elements', $tenantId, $establishmentId, ['curriculum_id' => $curriculumId], 'sort_index, code', 10000);

        return [$semesters, $units, $elements];
    }

    /** @return array<string, mixed> maquette modifiable (brouillon), sinon 409 explicite (RG-MAQ-1) */
    private function editable(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, self::MANAGE);
        if ($row['status'] === CurriculumStatus::Draft->value) {
            return $row;
        }
        if ($this->hasGrades($principal->tenantId, $establishmentId, $row)) {
            throw self::gradesConflict();
        }

        throw new HttpException(
            409,
            'Maquette publiée',
            'Une maquette publiée ne se modifie pas sur place : dépubliez-la (aucune note saisie) ou créez une nouvelle version (POST /higher/curricula/{id}/versions).',
            'https://api.scholaris.cm/errors/higher-curriculum-published',
        );
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id, string $permission): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [$permission]);
        $row = $this->store->findOrFail('hi_curricula', $principal->tenantId, $establishmentId, $id, 'Maquette introuvable.');
        $this->access->assertWritable($principal, $perimeter, self::scope($row), 'higher_curriculum', $id);

        return $row;
    }

    private static function gradesConflict(): HttpException
    {
        return new HttpException(
            409,
            'Maquette utilisée',
            'Des notes sont saisies sur cette maquette (RG-MAQ-1) : elle ne se modifie plus. Créez une nouvelle version (POST /higher/curricula/{id}/versions) ; les étudiants restent sur la version de leur année.',
            'https://api.scholaris.cm/errors/higher-curriculum-in-use',
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $details
     */
    private function touch(Principal $principal, string $establishmentId, array $row, string $action, array $details): void
    {
        $this->store->update('hi_curricula', $principal->tenantId, $establishmentId, ['updated_at' => $this->clock->now()], ['id' => (string) $row['id']]);
        $this->access->audit($principal, $action, 'higher_curriculum', (string) $row['id'], null, $details);
    }

    /** @param array<string, string> $key */
    private function assertNoLiveVersion(string $tenantId, string $establishmentId, array $key): void
    {
        foreach ([CurriculumStatus::Draft->value, CurriculumStatus::Published->value] as $status) {
            if ($this->store->count('hi_curricula', $tenantId, $establishmentId, $key + ['status' => $status]) > 0) {
                throw HttpException::conflict('Une maquette existe déjà pour cette filière, ce niveau et cette année : créez une nouvelle version.');
            }
        }
    }

    /** @param array<string, string> $key */
    private function nextVersion(string $tenantId, string $establishmentId, array $key): int
    {
        $last = $this->store->select('hi_curricula', $tenantId, $establishmentId, $key, 'version DESC', 1)[0]['version'] ?? 0;

        return (int) $last + 1;
    }

    /**
     * @param array<string, mixed> $program
     * @param array<string, string> $key
     */
    private function insertCurriculum(Principal $principal, string $tenantId, string $establishmentId, array $program, array $key, int $version, string $title, mixed $approvedRef, ?string $previous, ?string $copiedFrom): string
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_curricula', $tenantId, $establishmentId, $key + [
            'id' => $id, 'school_id' => $program['school_id'], 'department_id' => $program['department_id'], 'regime' => $program['regime'],
            'version' => $version, 'status' => CurriculumStatus::Draft->value, 'title' => $title,
            'approved_programme_ref' => is_string($approvedRef) ? $approvedRef : null, 'previous_version_id' => $previous, 'copied_from_id' => $copiedFrom,
            'published_at' => null, 'published_by' => null, 'grades_recorded_at' => null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $key
     * @return array<string, mixed>
     */
    private function cloneCurriculum(Principal $principal, string $establishmentId, array $source, array $key, ?string $previous, ?string $copiedFrom, string $action): array
    {
        $tenantId = $principal->tenantId;
        $program = $this->store->findOrFail('hi_programs', $tenantId, $establishmentId, (string) $source['program_id'], 'Filière introuvable.');

        return $this->store->transaction(function () use ($principal, $tenantId, $establishmentId, $source, $key, $previous, $copiedFrom, $action, $program): array {
            $id = $this->insertCurriculum($principal, $tenantId, $establishmentId, $program, $key, $this->nextVersion($tenantId, $establishmentId, $key), (string) $source['title'], $source['approved_programme_ref'], $previous, $copiedFrom);
            [$semesters, $units, $elements] = $this->content($tenantId, $establishmentId, (string) $source['id']);
            $now = $this->clock->now();
            $semesterIds = $unitIds = [];
            foreach ($semesters as $semester) {
                $semesterIds[(string) $semester['id']] = (string) new Ulid();
                $this->store->insert('hi_curriculum_semesters', $tenantId, $establishmentId, ['id' => $semesterIds[(string) $semester['id']], 'curriculum_id' => $id, 'ordinal' => (int) $semester['ordinal'], 'label' => (string) $semester['label'], 'created_at' => $now]);
            }
            foreach ($units as $unit) {
                $unitIds[(string) $unit['id']] = (string) new Ulid();
                $this->store->insert('hi_curriculum_units', $tenantId, $establishmentId, self::copyable($unit, ['id', 'tenant_id', 'establishment_id', 'curriculum_id', 'semester_id', 'catalog_subject_id', 'created_at', 'updated_at']) + [
                    'id' => $unitIds[(string) $unit['id']], 'curriculum_id' => $id, 'semester_id' => $semesterIds[(string) $unit['semester_id']] ?? '',
                    'catalog_subject_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($elements as $element) {
                $this->store->insert('hi_curriculum_elements', $tenantId, $establishmentId, self::copyable($element, ['id', 'tenant_id', 'establishment_id', 'curriculum_id', 'unit_id', 'catalog_element_id', 'created_at', 'updated_at']) + [
                    'id' => (string) new Ulid(), 'curriculum_id' => $id, 'unit_id' => $unitIds[(string) $element['unit_id']] ?? '',
                    'catalog_element_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $row = $this->store->findOrFail('hi_curricula', $tenantId, $establishmentId, $id);
            $this->access->audit($principal, $action, 'higher_curriculum', $id, null, ['source_id' => $source['id'], 'version' => (int) $row['version'], 'academic_year_id' => $key['academic_year_id']]);

            return $row;
        });
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $excluded
     * @return array<string, scalar|null>
     */
    private static function copyable(array $row, array $excluded): array
    {
        $values = [];
        foreach ($row as $column => $value) {
            if (!in_array($column, $excluded, true) && (is_scalar($value) || $value === null)) {
                $values[(string) $column] = $value;
            }
        }

        return $values;
    }

    /**
     * Volumes saisis en heures (au quart d'heure) => minutes.
     *
     * @param array<string, mixed> $v
     * @return array<string, int>
     */
    private static function volumes(array $v): array
    {
        $minutes = [];
        foreach (['cm' => 'cm_hours', 'td' => 'td_hours', 'tp' => 'tp_hours', 'personal' => 'personal_hours'] as $prefix => $field) {
            if (isset($v[$field])) {
                $minutes[$prefix . '_minutes'] = (int) round((float) $v[$field] * 60);
            }
        }

        return $minutes;
    }

    private function assertFreeCode(string $table, string $tenantId, string $establishmentId, string $curriculumId, string $code): void
    {
        if ($this->store->count($table, $tenantId, $establishmentId, ['curriculum_id' => $curriculumId, 'code' => $code]) > 0) {
            throw HttpException::conflict('Ce code est déjà utilisé dans la maquette.');
        }
    }
}
