<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/** Profil public et snapshots techniques annuels. Une clôture ne se modifie que par création d'une version corrective. */
final class InfrastructureSnapshotService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly TerritoryDirectory $territories,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function profile(string $tenantId, string $establishmentId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pp_school_profiles WHERE tenant_id = ? AND establishment_id = ?');
        $statement->execute([$tenantId, $establishmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    public function saveProfile(string $tenantId, string $establishmentId, string $name, string $schoolCode, string $territoryId): array
    {
        if ($this->territories->find($tenantId, $territoryId, (new DateTimeImmutable('now'))->format(DATE_ATOM)) === null) {
            throw HttpException::validation(['territory_id' => ['Territoire introuvable dans ce tenant.']]);
        }
        $pdo = $this->connections->pdo();
        $current = $this->profile($tenantId, $establishmentId);
        $now = self::now();
        if ($current === null) {
            $pdo->prepare(
                'INSERT INTO pp_school_profiles (id, tenant_id, establishment_id, name, school_code, territory_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $name, $schoolCode, $territoryId, $now, $now]);
        } else {
            $pdo->prepare(
                'UPDATE pp_school_profiles SET name = ?, school_code = ?, territory_id = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ?',
            )->execute([$name, $schoolCode, $territoryId, $now, $tenantId, $establishmentId]);
        }

        $profile = $this->profile($tenantId, $establishmentId) ?? throw new \LogicException('Profil école indisponible après écriture.');
        $registry = $pdo->prepare('SELECT id FROM pub_schools WHERE tenant_id = ? AND establishment_id = ?');
        $registry->execute([$tenantId, $establishmentId]);
        if ($registry->fetchColumn() !== false) {
            $pdo->prepare('UPDATE pub_schools SET official_code = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ?')
                ->execute([$schoolCode, $now, $tenantId, $establishmentId]);
        } else {
            $pdo->prepare(
                'INSERT INTO pub_schools (id, tenant_id, establishment_id, official_code, creation_act_ref, creation_act_date, subsystem, cycle, school_group_ref, status, latitude, longitude, phone, email, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, NULL, ?, NULL, NULL, NULL, NULL, ?, ?)',
            )->execute([(string) $profile['id'], $tenantId, $establishmentId, $schoolCode, 'francophone', 'primary', 'open', $now, $now]);
        }

        return $profile;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSnapshot(string $tenantId, string $establishmentId, array $data): array
    {
        $profile = $this->requireProfile($tenantId, $establishmentId);
        $values = self::snapshotValues($data);
        $territorySnapshot = $this->territorySnapshot($tenantId, (string) $profile['territory_id']);
        $pdo = $this->connections->pdo();
        $existing = $pdo->prepare('SELECT id FROM pp_infrastructure_snapshots WHERE tenant_id = ? AND establishment_id = ? AND school_year = ? LIMIT 1');
        $existing->execute([$tenantId, $establishmentId, $values['school_year']]);
        if ($existing->fetchColumn() !== false) {
            throw HttpException::conflict('Un snapshot existe déjà pour cette année ; utilisez une correction versionnée.');
        }

        return $this->insertSnapshot($tenantId, $establishmentId, $territorySnapshot, $values, 1, null);
    }

    /** @return array<string, mixed>|null */
    public function snapshot(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pp_infrastructure_snapshots WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function latestSnapshot(string $tenantId, string $establishmentId, string $schoolYear): ?array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM pp_infrastructure_snapshots WHERE tenant_id = ? AND establishment_id = ? AND school_year = ? ORDER BY version DESC LIMIT 1',
        );
        $statement->execute([$tenantId, $establishmentId, $schoolYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateDraft(string $tenantId, string $establishmentId, string $id, array $data): array
    {
        $snapshot = $this->requireSnapshot($tenantId, $establishmentId, $id);
        if ($snapshot['status'] !== 'draft') {
            throw HttpException::conflict('Un snapshot clôturé est immuable ; créez une version corrective.');
        }
        $values = self::snapshotValues($data);
        if ($values['school_year'] !== $snapshot['school_year']) {
            throw HttpException::validation(['school_year' => ['L’année ne peut pas changer sur un snapshot existant.']]);
        }
        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pp_infrastructure_snapshots SET classrooms_count = ?, classroom_condition_fr = ?, classroom_condition_en = ?, water_access = ?, '
            . 'latrines_count = ?, electricity = ?, connectivity = ?, equipment_fr = ?, equipment_en = ?, updated_at = ? '
            . 'WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND status = \'draft\'',
        )->execute([
            $values['classrooms_count'], $values['classroom_condition_fr'], $values['classroom_condition_en'], (int) $values['water_access'],
            $values['latrines_count'], (int) $values['electricity'], $values['connectivity'], $values['equipment_fr'], $values['equipment_en'],
            $now, $tenantId, $establishmentId, $id,
        ]);

        return $this->requireSnapshot($tenantId, $establishmentId, $id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correctSnapshot(string $tenantId, string $establishmentId, string $id, array $data, string $reason): array
    {
        $previous = $this->requireSnapshot($tenantId, $establishmentId, $id);
        if ($previous['status'] !== 'closed') {
            throw HttpException::conflict('Une correction versionnée exige un snapshot clôturé.');
        }
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => ['Motif de correction requis.']]);
        }
        $values = self::snapshotValues(array_replace($previous, $data));
        if ($values['school_year'] !== $previous['school_year']) {
            throw HttpException::validation(['school_year' => ['L’année du snapshot ne peut pas changer.']]);
        }
        $statement = $this->connections->pdo()->prepare(
            'SELECT MAX(version) FROM pp_infrastructure_snapshots WHERE tenant_id = ? AND establishment_id = ? AND school_year = ?',
        );
        $statement->execute([$tenantId, $establishmentId, $previous['school_year']]);
        $version = (int) $statement->fetchColumn() + 1;

        return $this->insertSnapshot($tenantId, $establishmentId, (string) $previous['territory_snapshot'], $values, $version, trim($reason));
    }

    /** @return array<string, mixed> */
    public function closeSnapshot(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $snapshot = $this->requireSnapshot($tenantId, $establishmentId, $id);
        if ($snapshot['status'] === 'closed') {
            return $snapshot;
        }
        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pp_infrastructure_snapshots SET status = \'closed\', closed_by = ?, closed_at = ?, updated_at = ? '
            . 'WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND status = \'draft\'',
        )->execute([$actorId, $now, $now, $tenantId, $establishmentId, $id]);

        return $this->requireSnapshot($tenantId, $establishmentId, $id);
    }

    /**
     * @param array{school_year: string, classrooms_count: int, classroom_condition_fr: string, classroom_condition_en: string, water_access: bool, latrines_count: int, electricity: bool, connectivity: string, equipment_fr: string, equipment_en: string} $values
     * @return array<string, mixed>
     */
    private function insertSnapshot(string $tenantId, string $establishmentId, string $territorySnapshot, array $values, int $version, ?string $reason): array
    {
        $id = (string) new Ulid();
        $now = self::now();
        $this->connections->pdo()->prepare(
            'INSERT INTO pp_infrastructure_snapshots '
            . '(id, tenant_id, establishment_id, territory_snapshot, school_year, version, classrooms_count, classroom_condition_fr, classroom_condition_en, water_access, '
            . 'latrines_count, electricity, connectivity, equipment_fr, equipment_en, status, correction_reason, closed_by, closed_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, NULL, NULL, ?, ?)',
        )->execute([
            $id, $tenantId, $establishmentId, $territorySnapshot, $values['school_year'], $version, $values['classrooms_count'], $values['classroom_condition_fr'],
            $values['classroom_condition_en'], (int) $values['water_access'], $values['latrines_count'], (int) $values['electricity'],
            $values['connectivity'], $values['equipment_fr'], $values['equipment_en'], $reason, $now, $now,
        ]);

        return $this->requireSnapshot($tenantId, $establishmentId, $id);
    }

    /** @param array<string, mixed> $data
     * @return array{school_year: string, classrooms_count: int, classroom_condition_fr: string, classroom_condition_en: string, water_access: bool, latrines_count: int, electricity: bool, connectivity: string, equipment_fr: string, equipment_en: string}
     */
    private static function snapshotValues(array $data): array
    {
        $errors = [];
        $year = $data['school_year'] ?? null;
        if (!is_string($year) || preg_match('/^\d{4}-\d{4}$/', $year) !== 1) {
            $errors['school_year'][] = 'Année scolaire synthétique attendue au format YYYY-YYYY.';
        }
        foreach (['classrooms_count', 'latrines_count'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                $errors[$field][] = 'Valeur entière positive ou nulle requise.';
            }
        }
        foreach (['classroom_condition_fr' => 500, 'classroom_condition_en' => 500, 'equipment_fr' => 1000, 'equipment_en' => 1000] as $field => $max) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen($data[$field]) > $max) {
                $errors[$field][] = 'Texte requis dans les deux langues et limité à ' . $max . ' caractères.';
            }
        }
        foreach (['water_access', 'electricity'] as $field) {
            if (!is_bool($data[$field] ?? null)) {
                $errors[$field][] = 'Valeur booléenne requise.';
            }
        }
        $connectivity = $data['connectivity'] ?? null;
        if (!is_string($connectivity) || !in_array($connectivity, ['unknown', 'none', 'limited', 'available'], true)) {
            $errors['connectivity'][] = 'État de connectivité invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'school_year' => $year,
            'classrooms_count' => $data['classrooms_count'],
            'classroom_condition_fr' => trim($data['classroom_condition_fr']),
            'classroom_condition_en' => trim($data['classroom_condition_en']),
            'water_access' => $data['water_access'],
            'latrines_count' => $data['latrines_count'],
            'electricity' => $data['electricity'],
            'connectivity' => $connectivity,
            'equipment_fr' => trim($data['equipment_fr']),
            'equipment_en' => trim($data['equipment_en']),
        ];
    }

    /** @return array<string, mixed> */
    private function requireSnapshot(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->snapshot($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Snapshot introuvable.');
    }

    /** @return array<string, mixed> */
    private function requireProfile(string $tenantId, string $establishmentId): array
    {
        return $this->profile($tenantId, $establishmentId) ?? throw HttpException::notFound('Profil école introuvable dans cet établissement.');
    }

    private function territorySnapshot(string $tenantId, string $territoryId): string
    {
        $lineage = $this->territories->lineage($tenantId, $territoryId, (new DateTimeImmutable('now'))->format(DATE_ATOM));
        if ($lineage === []) {
            throw HttpException::validation(['territory_id' => ['La lignée territoriale du profil est introuvable.']]);
        }
        $snapshot = array_map(static fn ($item): array => [
            'id' => $item->id,
            'type' => $item->type,
            'code' => $item->code,
            'label_fr' => $item->labelFr,
            'label_en' => $item->labelEn,
            'parent_id' => $item->parentId,
            'valid_from' => $item->validFrom,
            'valid_to' => $item->validTo,
            'version' => $item->version,
        ], $lineage);

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['version', 'classrooms_count', 'latrines_count'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['water_access', 'electricity'] as $field) {
            $row[$field] = (bool) $row[$field];
        }

        return $row;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
