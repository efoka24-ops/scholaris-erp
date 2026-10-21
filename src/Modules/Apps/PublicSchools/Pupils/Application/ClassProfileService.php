<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;

/** Profil public d'une structure-classe du socle (niveaux jumelés, double flux et titulaire daté). */
final class ClassProfileService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly StructureWriter $structures,
    ) {
    }

    /** @param array<string, mixed> $data
     * @return array{structure_id: string, academic_year_id: string, level_codes: list<string>, shift: string, homeroom_staff_id: ?string, homeroom_from: ?string}
     */
    public function create(string $tenantId, string $establishmentId, string $actorId, array $data): array
    {
        $name = self::text($data['name'] ?? null, 160, 'name');
        $yearId = self::text($data['academic_year_id'] ?? null, 26, 'academic_year_id');
        $orgUnitId = self::text($data['org_unit_id'] ?? null, 26, 'org_unit_id');
        $levels = self::levels($data['level_codes'] ?? null);
        $shift = is_string($data['shift'] ?? null) ? $data['shift'] : '';
        if (!in_array($shift, ['full_day', 'morning', 'afternoon'], true)) {
            throw HttpException::validation(['shift' => ['Valeur attendue : full_day, morning ou afternoon.']]);
        }
        $capacity = $data['capacity'] ?? null;
        if ($capacity !== null && (!is_int($capacity) || $capacity < 1 || $capacity > 10000)) {
            throw HttpException::validation(['capacity' => ['Capacité entre 1 et 10000 attendue.']]);
        }
        $staffId = self::nullableText($data['homeroom_staff_id'] ?? null, 40, 'homeroom_staff_id');
        $startsOn = self::nullableDate($data['homeroom_from'] ?? null, 'homeroom_from');
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $structureId = $this->structures->createStructure(
                $tenantId,
                $establishmentId,
                new StructureDraft(StructureDraft::SCHOOL_CLASS, $name, $orgUnitId, null, $capacity),
                $actorId,
            );
            $pdo->prepare(
                'INSERT INTO pub_class_profiles (id, tenant_id, establishment_id, structure_id, academic_year_id, level_codes, shift, homeroom_staff_id, homeroom_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $structureId, $yearId, implode(',', $levels), $shift, $staffId, $startsOn]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'structure_id' => $structureId,
            'academic_year_id' => $yearId,
            'level_codes' => $levels,
            'shift' => $shift,
            'homeroom_staff_id' => $staffId,
            'homeroom_from' => $startsOn,
        ];
    }

    private static function text(mixed $value, int $max, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            throw HttpException::validation([$field => ['Texte requis et longueur invalide.']]);
        }

        return trim($value);
    }

    private static function nullableText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::text($value, $max, $field);
    }

    /** @return list<string> */
    private static function levels(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw HttpException::validation(['level_codes' => ['Au moins un niveau est requis.']]);
        }
        $levels = [];
        foreach ($value as $level) {
            if (!is_string($level) || preg_match('/^[A-Za-z0-9_-]{1,16}$/', $level) !== 1) {
                throw HttpException::validation(['level_codes' => ['Code de niveau invalide.']]);
            }
            $levels[$level] = $level;
        }

        return array_values($levels);
    }

    private static function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw HttpException::validation([$field => ['Date AAAA-MM-JJ attendue.']]);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$field => ['Date invalide.']]);
        }

        return $value;
    }
}
