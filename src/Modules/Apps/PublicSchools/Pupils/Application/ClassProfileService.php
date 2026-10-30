<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * Profil public d'une structure-classe du socle (EX-CLA-01, EX-CLA-02) : niveaux jumelés (plusieurs niveaux, un enseignant), double
 * flux (matin, après-midi) et titulaire daté, avec l'historique des titulaires.
 */
final class ClassProfileService
{
    public const SHIFTS = ['full_day', 'morning', 'afternoon'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly StructureWriter $structures,
        private readonly StaffDirectory $staff,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{structure_id: string, academic_year_id: string, level_codes: list<string>, shift: string, homeroom_staff_id: ?string, homeroom_from: ?string}
     */
    public function create(string $tenantId, string $establishmentId, string $actorId, array $data): array
    {
        $name = self::text($data['name'] ?? null, 160, 'name');
        $yearId = self::text($data['academic_year_id'] ?? null, 26, 'academic_year_id');
        $orgUnitId = self::text($data['org_unit_id'] ?? null, 26, 'org_unit_id');
        $levels = self::levels($data['level_codes'] ?? null);
        $shift = self::shift($data['shift'] ?? null);
        $capacity = self::capacity($data['capacity'] ?? null);
        $staffId = self::nullableText($data['homeroom_staff_id'] ?? null, 26, 'homeroom_staff_id');
        $declaredFrom = self::nullableDate($data['homeroom_from'] ?? null, 'homeroom_from');
        $startsOn = $staffId === null ? $declaredFrom : ($declaredFrom ?? (new DateTimeImmutable())->format('Y-m-d'));
        if ($staffId !== null) {
            $this->assertTeacher($tenantId, $establishmentId, $staffId);
        }
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
            if ($staffId !== null && $startsOn !== null) {
                $this->insertHomeroom($tenantId, $establishmentId, $structureId, $staffId, $startsOn, $actorId);
            }
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

    /**
     * Met à jour le nom, la capacité, les niveaux (jumelage) et le flux d'une classe de l'école (champs absents inchangés).
     *
     * @param array<string, mixed> $data
     *
     * @throws HttpException 404 classe inconnue de l'école, 422 données invalides
     */
    public function update(string $tenantId, string $establishmentId, string $structureId, string $actorId, array $data): void
    {
        $this->requireProfile($tenantId, $establishmentId, $structureId);
        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = self::text($data['name'], 160, 'name');
        }
        if (array_key_exists('capacity', $data)) {
            $changes['capacity'] = self::capacity($data['capacity']);
        }
        $levels = array_key_exists('level_codes', $data) ? self::levels($data['level_codes']) : null;
        $shift = array_key_exists('shift', $data) ? self::shift($data['shift']) : null;
        if ($changes !== []) {
            $this->structures->updateStructure($tenantId, $establishmentId, $structureId, $changes, $actorId);
        }
        if ($levels !== null) {
            $this->connections->pdo()->prepare('UPDATE pub_class_profiles SET level_codes = ? WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?')
                ->execute([implode(',', $levels), $tenantId, $establishmentId, $structureId]);
        }
        if ($shift !== null) {
            $this->connections->pdo()->prepare('UPDATE pub_class_profiles SET shift = ? WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?')
                ->execute([$shift, $tenantId, $establishmentId, $structureId]);
        }
    }

    /**
     * Désigne le titulaire de la classe à partir d'une date (null = classe sans titulaire) ; le titulaire précédent est clos la veille.
     *
     * @return array{homeroom_staff_id: ?string, homeroom_from: string}
     *
     * @throws HttpException 404 classe inconnue, 422 enseignant inconnu de l'école ou date antérieure au titulaire en place
     */
    public function assignHomeroom(string $tenantId, string $establishmentId, string $structureId, ?string $staffId, string $startsOn, string $actorId): array
    {
        $this->requireProfile($tenantId, $establishmentId, $structureId);
        $date = self::nullableDate($startsOn, 'starts_on') ?? throw HttpException::validation(['starts_on' => ['Date d\'effet requise.']]);
        if ($staffId !== null) {
            $this->assertTeacher($tenantId, $establishmentId, $staffId);
        }
        $pdo = $this->connections->pdo();
        $open = $pdo->prepare('SELECT id, starts_on, staff_id FROM pub_class_homerooms WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND ends_on IS NULL');
        $open->execute([$tenantId, $establishmentId, $structureId]);
        $current = $open->fetch(PDO::FETCH_ASSOC);
        if (is_array($current) && $date <= (string) $current['starts_on']) {
            throw HttpException::validation(['starts_on' => ['La date d\'effet doit être postérieure à celle du titulaire en place (' . $current['starts_on'] . ').']]);
        }
        $pdo->beginTransaction();
        try {
            if (is_array($current)) {
                $end = (new DateTimeImmutable($date))->sub(new DateInterval('P1D'))->format('Y-m-d');
                $pdo->prepare('UPDATE pub_class_homerooms SET ends_on = ? WHERE tenant_id = ? AND id = ?')->execute([$end, $tenantId, $current['id']]);
            }
            if ($staffId !== null) {
                $this->insertHomeroom($tenantId, $establishmentId, $structureId, $staffId, $date, $actorId);
            }
            $pdo->prepare('UPDATE pub_class_profiles SET homeroom_staff_id = ?, homeroom_from = ? WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?')
                ->execute([$staffId, $date, $tenantId, $establishmentId, $structureId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['homeroom_staff_id' => $staffId, 'homeroom_from' => $date];
    }

    /** @return list<array{staff_id: string, starts_on: string, ends_on: ?string}> */
    public function homeroomHistory(string $tenantId, string $establishmentId, string $structureId): array
    {
        $this->requireProfile($tenantId, $establishmentId, $structureId);
        $query = $this->connections->pdo()->prepare(
            'SELECT staff_id, starts_on, ends_on FROM pub_class_homerooms WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? ORDER BY starts_on',
        );
        $query->execute([$tenantId, $establishmentId, $structureId]);

        return array_values(array_map(static fn (array $row): array => [
            'staff_id' => (string) $row['staff_id'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => $row['ends_on'] !== null ? (string) $row['ends_on'] : null,
        ], $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    private function insertHomeroom(string $tenantId, string $establishmentId, string $structureId, string $staffId, string $startsOn, string $actorId): void
    {
        $this->connections->pdo()->prepare(
            'INSERT INTO pub_class_homerooms (id, tenant_id, establishment_id, structure_id, staff_id, starts_on, ends_on, assigned_by) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
        )->execute([(string) new Ulid(), $tenantId, $establishmentId, $structureId, $staffId, $startsOn, $actorId]);
    }

    private function requireProfile(string $tenantId, string $establishmentId, string $structureId): void
    {
        $query = $this->connections->pdo()->prepare('SELECT 1 FROM pub_class_profiles WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?');
        $query->execute([$tenantId, $establishmentId, $structureId]);
        if ($query->fetchColumn() === false) {
            throw HttpException::notFound('Classe introuvable.');
        }
    }

    private function assertTeacher(string $tenantId, string $establishmentId, string $staffId): void
    {
        if (!in_array($staffId, $this->staff->teacherIdsIn($tenantId, $establishmentId), true)) {
            throw HttpException::validation(['homeroom_staff_id' => ['Enseignant inconnu ou inactif dans cette école.']]);
        }
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

    private static function shift(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::SHIFTS, true)) {
            throw HttpException::validation(['shift' => ['Valeur attendue : ' . implode(', ', self::SHIFTS) . '.']]);
        }

        return $value;
    }

    private static function capacity(mixed $value): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 1 || $value > 10000)) {
            throw HttpException::validation(['capacity' => ['Capacité entre 1 et 10000 attendue.']]);
        }

        return $value;
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
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw HttpException::validation([$field => ['Date invalide.']]);
        }

        return $value;
    }
}
