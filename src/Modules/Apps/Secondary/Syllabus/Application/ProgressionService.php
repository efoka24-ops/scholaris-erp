<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Syllabus\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientLine;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Progressions annuelles (EX-CTX-01, T862) : par matière, niveau (et série) et année, découpées en leçons avec heures et semaine
 * prévues ; total des heures = somme des leçons. Saisie et approbation par l'animateur pédagogique (`syllabus.manage`) ; les
 * leçons ne se remplacent plus dès qu'une séance du cahier de textes s'y rapporte (409).
 */
final class ProgressionService
{
    public const TABLE = 'se_progressions';
    public const LESSONS = 'se_progression_lessons';
    public const LOGS = 'se_lesson_logs';
    private const MAX_LESSONS = 300;
    private const MAX_WEEK = 60;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SyllabusAccess $syllabus,
        private readonly CoefficientBook $coefficients,
        private readonly EstablishmentProfiles $profiles,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $body academic_year_id, subject_ref, level_code, stream_id?, lessons
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, SyllabusAccess::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_progression');
        $in = new Input($body);
        $year = (string) $in->reference('academic_year_id');
        $subjectRef = (string) $in->reference('subject_ref');
        $level = (string) $in->code('level_code', 20);
        $stream = $in->reference('stream_id', false, true);
        $in->custom('lessons', static fn (mixed $v): array => self::parseLessons($v));
        $in->done();
        $lessons = self::parseLessons($body['lessons'] ?? null);
        $this->opened($tenantId, $establishmentId);
        $line = $this->line($tenantId, $establishmentId, $year, $level, $stream, $subjectRef);
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $year, 'se_subject_id' => $subjectRef, 'level_code' => $level]) as $existing) {
            if ($existing['stream_id'] === $stream) {
                throw HttpException::conflict('Une progression existe déjà pour cette matière, ce niveau et cette année : modifiez ses leçons.');
            }
        }
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(), 'academic_year_id' => $year, 'se_subject_id' => $subjectRef, 'subject_id' => $line->subjectId, 'subject_label' => $line->label,
            'level_code' => $level, 'stream_id' => $stream, 'total_hours' => self::total($lessons), 'status' => 'draft', 'approved_by' => null, 'approved_at' => null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $row, $lessons, $now): void {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
            $this->insertLessons($tenantId, $establishmentId, (string) $row['id'], $lessons, $now);
        });
        $this->access->audit($principal, 'secondary.syllabus.progression_created', 'se_progression', (string) $row['id'], null, $row);

        return $this->show($principal, $establishmentId, (string) $row['id']);
    }

    /**
     * @param array<string, mixed> $body lessons
     * @return array<string, mixed>
     */
    public function replaceLessons(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, SyllabusAccess::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_progression');
        $progression = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Progression introuvable.');
        $in = new Input($body);
        $in->custom('lessons', static fn (mixed $v): array => self::parseLessons($v));
        $in->done();
        $lessons = self::parseLessons($body['lessons'] ?? null);
        $old = $this->store->select(self::LESSONS, $tenantId, $establishmentId, ['progression_id' => $id], 'sort_order', self::MAX_LESSONS);
        foreach ($old as $lesson) {
            if ($this->store->count(self::LOGS, $tenantId, $establishmentId, ['lesson_id' => (string) $lesson['id']]) > 0) {
                throw HttpException::conflict('Des séances du cahier de textes se rapportent déjà à ces leçons : elles ne se remplacent plus.');
            }
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $lessons, $now): void {
            $this->store->delete(self::LESSONS, $tenantId, $establishmentId, ['progression_id' => $id]);
            $this->insertLessons($tenantId, $establishmentId, $id, $lessons, $now);
            $this->store->update(self::TABLE, $tenantId, $establishmentId, ['total_hours' => self::total($lessons), 'status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'updated_at' => $now], ['id' => $id]);
        });
        $this->access->audit($principal, 'secondary.syllabus.lessons_replaced', 'se_progression', $id, ['total_hours' => $progression['total_hours'], 'lessons' => count($old)], ['total_hours' => self::total($lessons), 'lessons' => count($lessons)]);

        return $this->show($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function approve(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, SyllabusAccess::MANAGE);
        $this->access->assertWhole($principal, $perimeter, 'se_progression');
        $progression = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Progression introuvable.');
        if ($progression['status'] === 'approved') {
            throw HttpException::conflict('Progression déjà approuvée.');
        }
        $values = ['status' => 'approved', 'approved_by' => $principal->userId, 'approved_at' => $this->clock->now(), 'updated_at' => $this->clock->now()];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $values, ['id' => $id]);
        $this->access->audit($principal, 'secondary.syllabus.progression_approved', 'se_progression', $id, ['status' => $progression['status']], $values);

        return $this->show($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        [, $inspector] = $this->syllabus->readable($principal, $establishmentId);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Progression introuvable.');
        $this->syllabus->logInspection($principal, $inspector, 'se_progression', $id);

        return self::progression($row) + ['lessons' => $this->lessons($principal->tenantId, $establishmentId, $id)];
    }

    /** @return list<array<string, mixed>> */
    public function lessons(string $tenantId, string $establishmentId, string $progressionId): array
    {
        $list = [];
        foreach ($this->store->select(self::LESSONS, $tenantId, $establishmentId, ['progression_id' => $progressionId], 'sort_order', self::MAX_LESSONS) as $row) {
            $list[] = ['id' => (string) $row['id'], 'sort_order' => (int) $row['sort_order'], 'title' => (string) $row['title'], 'planned_hours' => (float) $row['planned_hours'], 'planned_week' => (int) $row['planned_week']];
        }

        return $list;
    }

    /**
     * Progression d'une matière pour le niveau et la série d'une classe (celle de la série, sinon celle du niveau).
     *
     * @return array<string, mixed>|null
     */
    public function forClass(string $tenantId, string $establishmentId, string $academicYearId, string $subjectRef, string $levelCode, ?string $streamId): ?array
    {
        $fallback = null;
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'se_subject_id' => $subjectRef, 'level_code' => $levelCode]) as $row) {
            if ($row['stream_id'] !== null && $row['stream_id'] === $streamId) {
                return $row;
            }
            if ($row['stream_id'] === null) {
                $fallback = $row;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function progression(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'academic_year_id' => (string) $row['academic_year_id'], 'subject_ref' => (string) $row['se_subject_id'],
            'subject_id' => (string) $row['subject_id'], 'subject_label' => (string) $row['subject_label'], 'level_code' => (string) $row['level_code'],
            'stream_id' => $row['stream_id'] === null ? null : (string) $row['stream_id'], 'total_hours' => (float) $row['total_hours'], 'status' => (string) $row['status'],
            'approved_by' => $row['approved_by'] === null ? null : (string) $row['approved_by'], 'approved_at' => $row['approved_at'] === null ? null : (string) $row['approved_at'],
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function opened(string $tenantId, string $establishmentId): void
    {
        if (!$this->profiles->forEstablishment($tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
    }

    private function line(string $tenantId, string $establishmentId, string $year, string $level, ?string $stream, string $subjectRef): CoefficientLine
    {
        foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $year, $level, $stream) as $line) {
            if ($line->localSubjectId === $subjectRef) {
                return $line;
            }
        }

        throw HttpException::validation(['subject_ref' => ['Matière non paramétrée pour ce niveau et cette année (paramétrage, coefficients).']]);
    }

    /** @param list<array{title: string, planned_hours: float, planned_week: int}> $lessons */
    private function insertLessons(string $tenantId, string $establishmentId, string $progressionId, array $lessons, string $now): void
    {
        foreach ($lessons as $index => $lesson) {
            $this->store->insert(self::LESSONS, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'progression_id' => $progressionId, 'sort_order' => $index + 1, 'title' => $lesson['title'],
                'planned_hours' => $lesson['planned_hours'], 'planned_week' => $lesson['planned_week'], 'created_at' => $now,
            ]);
        }
    }

    /** @param list<array{title: string, planned_hours: float, planned_week: int}> $lessons */
    private static function total(array $lessons): float
    {
        return round(array_sum(array_column($lessons, 'planned_hours')), 2);
    }

    /** @return list<array{title: string, planned_hours: float, planned_week: int}> */
    private static function parseLessons(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_LESSONS) {
            throw new \InvalidArgumentException(sprintf('Liste de 1 à %d leçons attendue.', self::MAX_LESSONS));
        }
        $lessons = [];
        foreach ($value as $index => $item) {
            $position = (int) $index + 1;
            $title = is_array($item) ? ($item['title'] ?? null) : null;
            $hours = is_array($item) ? ($item['planned_hours'] ?? null) : null;
            $week = is_array($item) ? ($item['planned_week'] ?? null) : null;
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 200) {
                throw new \InvalidArgumentException(sprintf('Leçon %d : titre de 1 à 200 caractères.', $position));
            }
            if ((!is_int($hours) && !is_float($hours)) || $hours <= 0 || $hours > 200 || abs($hours * 4 - round($hours * 4)) > 1e-6) {
                throw new \InvalidArgumentException(sprintf('Leçon %d : heures prévues positives, au quart d\'heure.', $position));
            }
            if (!is_int($week) || $week < 1 || $week > self::MAX_WEEK) {
                throw new \InvalidArgumentException(sprintf('Leçon %d : semaine prévue de 1 à %d.', $position, self::MAX_WEEK));
            }
            $lessons[] = ['title' => trim($title), 'planned_hours' => (float) $hours, 'planned_week' => $week];
        }

        return $lessons;
    }
}
