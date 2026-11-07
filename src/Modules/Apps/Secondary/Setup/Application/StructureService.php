<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Domain\LevelDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/**
 * Structure pédagogique de l'établissement (T821, EX-SEC-07) : niveaux (structures `level` du socle), séries et spécialités ouvertes
 * (lignes du catalogue de la plateforme, Catalogue\StreamDirectory) et classes (structures `class` du socle) avec leur profil.
 *  - RG-ORG-1 : un niveau, une série ou une classe d'un cycle, d'un enseignement ou d'une section absent du profil → 422 ;
 *  - RG-ORG-2 : une classe a section, enseignement, niveau et, au 2nd cycle ou en technique, une série ou spécialité (sinon 422).
 * Les niveaux d'exemple (LevelDefaults, « à confirmer ») ne sont chargés qu'à la demande.
 */
final class StructureService
{
    public const READ = ['secondary.settings.read', 'secondary.settings.write', 'secondary.settings.manage', 'secondary.profile.read', 'secondary.profile.manage', 'secondary.enrol.read', 'secondary.enrol.manage'];
    public const WRITE = ['secondary.settings.write', 'secondary.settings.manage'];
    public const CLASS_WRITE = ['secondary.settings.write', 'secondary.settings.manage', 'secondary.enrol.manage'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentProfiles $profiles,
        private readonly StreamDirectory $streams,
        private readonly StructureWriter $writer,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function levels(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return array_map(self::level(...), $this->store->select('se_levels', $principal->tenantId, $establishmentId, [], 'section_code, teaching_kind, sort_order, code', 500));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createLevel(Principal $principal, string $establishmentId, array $body): array
    {
        $profile = $this->writable($principal, $establishmentId, self::WRITE);
        $in = new Input($body);
        $code = $in->code('code', 20);
        $label = $in->string('label', 120);
        $cycle = $in->enum('cycle_code', Cycles::ALL);
        $section = $in->enum('section_code', Cycles::SECTIONS);
        $teaching = $in->enum('teaching_kind', Cycles::TEACHING_KINDS);
        $order = $in->int('sort_order', 0, 99, false);
        $exam = $in->code('exam_code', 20, false);
        $in->done();
        $this->assertOffered($profile, (string) $cycle, (string) $teaching, (string) $section);
        $id = $this->insertLevel($principal, $establishmentId, (string) $code, (string) $label, (string) $cycle, (string) $section, (string) $teaching, $order ?? 0, $exam, 'custom');

        return self::level($this->store->findOrFail('se_levels', $principal->tenantId, $establishmentId, $id));
    }

    /**
     * Charge les niveaux d'exemple (LevelDefaults) qui correspondent au profil et qui n'existent pas encore.
     *
     * @return list<array<string, mixed>>
     */
    public function loadExampleLevels(Principal $principal, string $establishmentId): array
    {
        $profile = $this->writable($principal, $establishmentId, self::WRITE);
        $existing = [];
        foreach ($this->store->select('se_levels', $principal->tenantId, $establishmentId, [], 'id', 500) as $row) {
            $existing[$row['section_code'] . '|' . $row['teaching_kind'] . '|' . $row['code']] = true;
        }
        foreach ($profile->sections as $section) {
            foreach ($profile->teachingKinds as $teaching) {
                foreach (LevelDefaults::EXAMPLE[$section][$teaching] ?? [] as $index => $level) {
                    if (!$profile->offersCycle($level['cycle']) || isset($existing[$section . '|' . $teaching . '|' . $level['code']])) {
                        continue;
                    }
                    $this->insertLevel($principal, $establishmentId, $level['code'], $level['label'], $level['cycle'], $section, $teaching, $index, null, 'example');
                }
            }
        }

        return $this->levels($principal, $establishmentId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateLevel(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->writable($principal, $establishmentId, self::WRITE);
        $row = $this->store->findOrFail('se_levels', $principal->tenantId, $establishmentId, $id, 'Niveau introuvable.');
        $in = new Input($body, true);
        $label = $in->string('label', 120, false);
        $order = $in->int('sort_order', 0, 99, false);
        $exam = $in->code('exam_code', 20, false);
        $active = $in->bool('active', false);
        $values = $in->done();
        $changes = ['updated_at' => $this->clock->now()];
        if ($label !== null) {
            $changes['label'] = $label;
            $this->writer->updateStructure($principal->tenantId, $establishmentId, (string) $row['structure_id'], ['name' => $label], $principal->userId);
        }
        if ($order !== null) {
            $changes['sort_order'] = $order;
        }
        if (array_key_exists('exam_code', $values)) {
            $changes['exam_code'] = $exam;
        }
        if ($active !== null) {
            $changes['active'] = $active ? 1 : 0;
        }
        $this->store->update('se_levels', $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.level.updated', 'se_level', $id, $row, $changes);

        return self::level($this->store->findOrFail('se_levels', $principal->tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function openedStreams(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);

        return array_map(self::stream(...), $this->store->select('se_streams', $principal->tenantId, $establishmentId, [], 'cycle_code, code', 500));
    }

    /**
     * @param array<string, mixed> $body stream_ref_id, authorization_id?
     * @return array<string, mixed>
     */
    public function openStream(Principal $principal, string $establishmentId, array $body): array
    {
        $profile = $this->writable($principal, $establishmentId, self::WRITE);
        $in = new Input($body);
        $ref = (string) $in->reference('stream_ref_id');
        $authorization = $in->reference('authorization_id', false, true);
        $in->done();
        $info = $this->streams->find($principal->tenantId, $establishmentId, $ref);
        if ($info === null || $info->hidden) {
            throw HttpException::validation(['stream_ref_id' => ['Série ou spécialité introuvable ou masquée dans le catalogue de l\'établissement.']]);
        }
        $this->assertOffered($profile, $info->cycleCode, $info->teachingKind, $info->sectionCode, 'stream_ref_id');
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_streams', $principal->tenantId, $establishmentId, [
            'id' => $id, 'stream_ref_id' => $info->id, 'stream_kind' => $info->streamKind, 'teaching_kind' => $info->teachingKind, 'code' => $info->code,
            'label' => $info->label, 'cycle_code' => $info->cycleCode, 'section_code' => $info->sectionCode, 'authorization_id' => $authorization,
            'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.stream.opened', 'se_stream', $id, null, ['code' => $info->code, 'cycle' => $info->cycleCode]);

        return self::stream($this->store->findOrFail('se_streams', $principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $body active?, authorization_id?
     * @return array<string, mixed>
     */
    public function updateStream(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->writable($principal, $establishmentId, self::WRITE);
        $row = $this->store->findOrFail('se_streams', $principal->tenantId, $establishmentId, $id, 'Série ou spécialité introuvable.');
        $in = new Input($body, true);
        $active = $in->bool('active', false);
        $authorization = $in->reference('authorization_id', false, true);
        $values = $in->done();
        $changes = ['updated_at' => $this->clock->now()];
        if ($active !== null) {
            $changes['active'] = $active ? 1 : 0;
        }
        if (array_key_exists('authorization_id', $values)) {
            $changes['authorization_id'] = $authorization;
        }
        $this->store->update('se_streams', $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.stream.updated', 'se_stream', $id, $row, $changes);

        return self::stream($this->store->findOrFail('se_streams', $principal->tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function classes(Principal $principal, string $establishmentId, ?string $academicYearId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $where = $academicYearId === null ? [] : ['academic_year_id' => $academicYearId];

        return array_map(self::schoolClass(...), $this->store->select('se_class_profiles', $principal->tenantId, $establishmentId, $where, 'academic_year_id, level_code, label', 1000));
    }

    /**
     * @param array<string, mixed> $body label, level_id, academic_year_id, stream_id?, capacity?, exam_code?, head_teacher_person_id?
     * @return array<string, mixed>
     */
    public function createClass(Principal $principal, string $establishmentId, array $body): array
    {
        $profile = $this->writable($principal, $establishmentId, self::CLASS_WRITE);
        $tenantId = $principal->tenantId;
        $in = new Input($body);
        $label = (string) $in->string('label', 120);
        $levelId = (string) $in->reference('level_id');
        $year = (string) $in->reference('academic_year_id');
        $streamId = $in->reference('stream_id', false, true);
        $capacity = $in->int('capacity', 1, 500, false, true);
        $exam = $in->code('exam_code', 20, false);
        $head = $in->reference('head_teacher_person_id', false, true);
        $in->done();
        $level = $this->store->find('se_levels', $tenantId, $establishmentId, $levelId);
        if ($level === null || (int) $level['active'] !== 1) {
            throw HttpException::validation(['level_id' => ['Niveau introuvable ou désactivé.']]);
        }
        $this->assertOffered($profile, (string) $level['cycle_code'], (string) $level['teaching_kind'], (string) $level['section_code'], 'level_id');
        $stream = null;
        if ($streamId !== null) {
            $stream = $this->store->find('se_streams', $tenantId, $establishmentId, $streamId);
            if ($stream === null || (int) $stream['active'] !== 1) {
                throw HttpException::validation(['stream_id' => ['Série ou spécialité non ouverte dans l\'établissement.']]);
            }
            if ($stream['cycle_code'] !== $level['cycle_code'] || $stream['teaching_kind'] !== $level['teaching_kind'] || $stream['section_code'] !== $level['section_code']) {
                throw HttpException::validation(['stream_id' => ['La série ou spécialité ne correspond pas au cycle, à l\'enseignement ou à la section du niveau (RG-ORG-2).']]);
            }
        } elseif ($level['cycle_code'] === Cycles::SECOND || $level['teaching_kind'] === Cycles::TEACHING_TECHNICAL) {
            throw HttpException::validation(['stream_id' => ['Au 2nd cycle ou en enseignement technique, une classe a une série ou une spécialité (RG-ORG-2).']]);
        }
        $structureId = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::SCHOOL_CLASS, $label, null, (string) $level['structure_id'], $capacity), $principal->userId);
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_class_profiles', $tenantId, $establishmentId, [
            'id' => $id, 'structure_id' => $structureId, 'academic_year_id' => $year, 'label' => $label, 'section_code' => (string) $level['section_code'],
            'teaching_kind' => (string) $level['teaching_kind'], 'cycle_code' => (string) $level['cycle_code'], 'level_id' => $levelId, 'level_code' => (string) $level['code'],
            'stream_id' => $stream === null ? null : (string) $stream['id'], 'exam_code' => $exam ?? (is_string($level['exam_code']) ? $level['exam_code'] : null),
            'capacity' => $capacity, 'head_teacher_person_id' => $head, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.class.created', 'se_class_profile', $id, null, ['structure_id' => $structureId, 'level' => $level['code']]);

        return self::schoolClass($this->store->findOrFail('se_class_profiles', $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $body label?, capacity?, head_teacher_person_id?, exam_code?
     * @return array<string, mixed>
     */
    public function updateClass(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->writable($principal, $establishmentId, self::CLASS_WRITE);
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail('se_class_profiles', $tenantId, $establishmentId, $id, 'Classe introuvable.');
        $in = new Input($body, true);
        $label = $in->string('label', 120, false);
        $capacity = $in->int('capacity', 1, 500, false, true);
        $exam = $in->code('exam_code', 20, false);
        $head = $in->reference('head_teacher_person_id', false, true);
        $values = $in->done();
        $changes = ['updated_at' => $this->clock->now()];
        $structure = [];
        if ($label !== null) {
            $changes['label'] = $structure['name'] = $label;
        }
        if (array_key_exists('capacity', $values)) {
            $changes['capacity'] = $structure['capacity'] = $capacity;
        }
        if (array_key_exists('exam_code', $values)) {
            $changes['exam_code'] = $exam;
        }
        if (array_key_exists('head_teacher_person_id', $values)) {
            $changes['head_teacher_person_id'] = $head;
        }
        if ($structure !== []) {
            $this->writer->updateStructure($tenantId, $establishmentId, (string) $row['structure_id'], $structure, $principal->userId);
        }
        $this->store->update('se_class_profiles', $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.class.updated', 'se_class_profile', $id, $row, $changes);

        return self::schoolClass($this->store->findOrFail('se_class_profiles', $tenantId, $establishmentId, $id));
    }

    /** @param list<string> $permissions */
    private function writable(Principal $principal, string $establishmentId, array $permissions): EstablishmentProfile
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWhole($principal, $perimeter, 'se_setup');
        $profile = $this->profiles->forEstablishment($principal->tenantId, $establishmentId);
        if (!$profile->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }

        return $profile;
    }

    private function assertOffered(EstablishmentProfile $profile, string $cycle, string $teaching, string $section, string $field = 'cycle_code'): void
    {
        $errors = [];
        if (!$profile->offersCycle($cycle)) {
            $errors[] = 'Le cycle « ' . $cycle . ' » n\'est pas offert par le profil de l\'établissement (RG-ORG-1).';
        }
        if (!$profile->offersTeaching($teaching)) {
            $errors[] = 'L\'enseignement « ' . $teaching . ' » n\'est pas offert par le profil de l\'établissement.';
        }
        if (!in_array($section, $profile->sections, true)) {
            $errors[] = 'La section « ' . $section . ' » n\'est pas ouverte dans l\'établissement.';
        }
        if ($errors !== []) {
            throw HttpException::validation([$field => $errors]);
        }
    }

    private function insertLevel(Principal $principal, string $establishmentId, string $code, string $label, string $cycle, string $section, string $teaching, int $order, ?string $exam, string $origin): string
    {
        $tenantId = $principal->tenantId;
        if ($this->store->count('se_levels', $tenantId, $establishmentId, ['section_code' => $section, 'teaching_kind' => $teaching, 'code' => $code]) > 0) {
            throw HttpException::conflict('Ce niveau existe déjà pour cette section et cet enseignement.');
        }
        $structureId = $this->writer->createStructure($tenantId, $establishmentId, new StructureDraft(StructureDraft::LEVEL, $label), $principal->userId);
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_levels', $tenantId, $establishmentId, [
            'id' => $id, 'code' => $code, 'label' => $label, 'cycle_code' => $cycle, 'section_code' => $section, 'teaching_kind' => $teaching,
            'sort_order' => $order, 'exam_code' => $exam, 'structure_id' => $structureId, 'origin' => $origin, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.level.created', 'se_level', $id, null, ['code' => $code, 'origin' => $origin]);

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function level(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'code' => (string) $row['code'], 'label' => (string) $row['label'], 'cycle_code' => (string) $row['cycle_code'],
            'section_code' => (string) $row['section_code'], 'teaching_kind' => (string) $row['teaching_kind'], 'sort_order' => (int) $row['sort_order'],
            'exam_code' => $row['exam_code'], 'structure_id' => (string) $row['structure_id'], 'origin' => (string) $row['origin'],
            'confirmation' => $row['origin'] === 'example' ? 'to_confirm' : 'establishment', 'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function stream(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'stream_ref_id' => (string) $row['stream_ref_id'], 'stream_kind' => (string) $row['stream_kind'],
            'teaching_kind' => (string) $row['teaching_kind'], 'code' => (string) $row['code'], 'label' => (string) $row['label'],
            'cycle_code' => (string) $row['cycle_code'], 'section_code' => (string) $row['section_code'], 'authorization_id' => $row['authorization_id'],
            'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function schoolClass(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'structure_id' => (string) $row['structure_id'], 'academic_year_id' => (string) $row['academic_year_id'],
            'label' => (string) $row['label'], 'section_code' => (string) $row['section_code'], 'teaching_kind' => (string) $row['teaching_kind'],
            'cycle_code' => (string) $row['cycle_code'], 'level_id' => (string) $row['level_id'], 'level_code' => (string) $row['level_code'],
            'stream_id' => $row['stream_id'], 'exam_code' => $row['exam_code'], 'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'],
            'head_teacher_person_id' => $row['head_teacher_person_id'],
        ];
    }
}
