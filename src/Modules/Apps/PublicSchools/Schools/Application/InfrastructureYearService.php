<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

/**
 * Infrastructures annuelles de l'école (EX-ECO-02, EX-ECO-03, EX-ECO-05) : salles (matériau, état), tables-bancs, points d'eau,
 * latrines séparées, électricité, clôture, cantine, bibliothèque, équipements numériques et réseau observé. Une année a des
 * versions numérotées ; une version close est immuable, la correction crée la version n+1 avec son motif (reprise de la logique de
 * InfrastructureSnapshotService, sur les tables `pub_infrastructure_years` et `pub_classrooms`).
 */
final class InfrastructureYearService
{
    public const NETWORKS = ['unknown', 'none', 'limited', 'available'];

    private const COUNTS = ['desks_count', 'water_points', 'latrines_girls', 'latrines_boys'];
    private const FLAGS = ['has_electricity', 'has_fence', 'has_canteen', 'has_library'];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data): array
    {
        $values = self::values($data);
        $existing = $this->connections->pdo()->prepare(
            'SELECT 1 FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ? AND year_label = ? LIMIT 1',
        );
        $existing->execute([$tenantId, $establishmentId, $values['year_label']]);
        if ($existing->fetchColumn() !== false) {
            throw HttpException::conflict('Une version existe déjà pour cette année ; utilisez une correction versionnée.');
        }

        return $this->insert($tenantId, $establishmentId, $values, 1, null);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        $query->execute([$tenantId, $establishmentId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->present($tenantId, $row) : null;
    }

    /** @return array<string, mixed>|null */
    public function latest(string $tenantId, string $establishmentId, string $yearLabel): ?array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ? AND year_label = ? ORDER BY version DESC LIMIT 1',
        );
        $query->execute([$tenantId, $establishmentId, $yearLabel]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->present($tenantId, $row) : null;
    }

    /**
     * Versions de l'école (une année ou toutes), de la plus ancienne année à la plus récente, sans le détail des salles.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $tenantId, string $establishmentId, ?string $yearLabel): array
    {
        $sql = 'SELECT * FROM pub_infrastructure_years WHERE tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        if ($yearLabel !== null) {
            $sql .= ' AND year_label = ?';
            $params[] = $yearLabel;
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY year_label, version');
        $query->execute($params);

        return array_values(array_map(static fn (array $row): array => self::cast($row), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateDraft(string $tenantId, string $establishmentId, string $id, array $data): array
    {
        $current = $this->require($tenantId, $establishmentId, $id);
        if ($current['state'] !== 'draft') {
            throw HttpException::conflict('Une version close est immuable ; créez une version corrective.');
        }
        $values = self::values(array_replace($this->editable($current), $data));
        if ($values['year_label'] !== $current['year_label']) {
            throw HttpException::validation(['year_label' => ['L\'année ne peut pas changer sur une version existante.']]);
        }
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE pub_infrastructure_years SET desks_count = ?, water_points = ?, latrines_girls = ?, latrines_boys = ?, has_electricity = ?, has_fence = ?, '
                . 'has_canteen = ?, has_library = ?, network_observed = ?, digital_equipment = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND state = \'draft\'',
            )->execute([
                $values['desks_count'], $values['water_points'], $values['latrines_girls'], $values['latrines_boys'], (int) $values['has_electricity'],
                (int) $values['has_fence'], (int) $values['has_canteen'], (int) $values['has_library'], $values['network_observed'],
                $values['digital_equipment'], $tenantId, $establishmentId, $id,
            ]);
            if (array_key_exists('classrooms', $data)) {
                $pdo->prepare('DELETE FROM pub_classrooms WHERE tenant_id = ? AND infrastructure_year_id = ?')->execute([$tenantId, $id]);
                $this->insertClassrooms($tenantId, $establishmentId, $id, $values['classrooms']);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->require($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function close(string $tenantId, string $establishmentId, string $id, string $actorId): array
    {
        $current = $this->require($tenantId, $establishmentId, $id);
        if ($current['state'] === 'closed') {
            return $current;
        }
        $this->connections->pdo()->prepare(
            'UPDATE pub_infrastructure_years SET state = \'closed\', closed_by = ?, closed_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ? AND state = \'draft\'',
        )->execute([$actorId, self::now(), $tenantId, $establishmentId, $id]);

        return $this->require($tenantId, $establishmentId, $id);
    }

    /**
     * Correction d'une version close : version n+1 (brouillon) avec le motif ; les valeurs non fournies sont reprises de la version corrigée.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function correct(string $tenantId, string $establishmentId, string $id, array $data, string $reason): array
    {
        $previous = $this->require($tenantId, $establishmentId, $id);
        if ($previous['state'] !== 'closed') {
            throw HttpException::conflict('Une correction versionnée exige une version close.');
        }
        if (trim($reason) === '' || mb_strlen(trim($reason)) > 500) {
            throw HttpException::validation(['reason' => ['Motif de correction requis (500 caractères au plus).']]);
        }
        $latest = $this->latest($tenantId, $establishmentId, (string) $previous['year_label']);
        if ($latest === null || $latest['id'] !== $previous['id']) {
            throw HttpException::conflict('Une version plus récente existe pour cette année : corrigez la dernière version.');
        }
        $values = self::values(array_replace($this->editable($previous), $data, ['year_label' => $previous['year_label']]));

        return $this->insert($tenantId, $establishmentId, $values, (int) $previous['version'] + 1, trim($reason));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function insert(string $tenantId, string $establishmentId, array $values, int $version, ?string $reason): array
    {
        $id = (string) new Ulid();
        $pdo = $this->connections->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO pub_infrastructure_years (id, tenant_id, establishment_id, year_label, version, state, desks_count, water_points, latrines_girls, latrines_boys, '
                . 'has_electricity, has_fence, has_canteen, has_library, network_observed, digital_equipment, correction_reason, closed_by, closed_at, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)',
            )->execute([
                $id, $tenantId, $establishmentId, $values['year_label'], $version, $values['desks_count'], $values['water_points'], $values['latrines_girls'],
                $values['latrines_boys'], (int) $values['has_electricity'], (int) $values['has_fence'], (int) $values['has_canteen'], (int) $values['has_library'],
                $values['network_observed'], $values['digital_equipment'], $reason, self::now(),
            ]);
            $this->insertClassrooms($tenantId, $establishmentId, $id, $values['classrooms']);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->require($tenantId, $establishmentId, $id);
    }

    /** @param list<array{label: string, material: string, room_state: string, in_use: bool}> $classrooms */
    private function insertClassrooms(string $tenantId, string $establishmentId, string $yearId, array $classrooms): void
    {
        $insert = $this->connections->pdo()->prepare(
            'INSERT INTO pub_classrooms (id, tenant_id, establishment_id, infrastructure_year_id, label, material, room_state, in_use) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($classrooms as $room) {
            $insert->execute([(string) new Ulid(), $tenantId, $establishmentId, $yearId, $room['label'], $room['material'], $room['room_state'], (int) $room['in_use']]);
        }
    }

    /** @return array<string, mixed> */
    private function require(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Version d\'infrastructure introuvable.');
    }

    /**
     * Valeurs modifiables d'une version existante, au format de la saisie (pour fusionner avec les changements demandés).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function editable(array $row): array
    {
        return array_intersect_key($row, array_flip([...self::COUNTS, ...self::FLAGS, 'year_label', 'network_observed', 'digital_equipment', 'classrooms']));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(string $tenantId, array $row): array
    {
        $row = self::cast($row);
        $query = $this->connections->pdo()->prepare(
            'SELECT label, material, room_state, in_use FROM pub_classrooms WHERE tenant_id = ? AND infrastructure_year_id = ? ORDER BY label, id',
        );
        $query->execute([$tenantId, $row['id']]);
        $row['classrooms'] = array_values(array_map(static fn (array $room): array => [
            'label' => (string) $room['label'],
            'material' => (string) $room['material'],
            'room_state' => (string) $room['room_state'],
            'in_use' => (bool) $room['in_use'],
        ], $query->fetchAll(PDO::FETCH_ASSOC) ?: []));

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['version', ...self::COUNTS] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (self::FLAGS as $field) {
            $row[$field] = (bool) $row[$field];
        }
        $equipment = $row['digital_equipment'] ?? null;
        $decoded = is_string($equipment) && $equipment !== '' ? json_decode($equipment, true) : [];
        $row['digital_equipment'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{year_label: string, desks_count: int, water_points: int, latrines_girls: int, latrines_boys: int, has_electricity: bool, has_fence: bool, has_canteen: bool, has_library: bool, network_observed: ?string, digital_equipment: ?string, classrooms: list<array{label: string, material: string, room_state: string, in_use: bool}>}
     */
    private static function values(array $data): array
    {
        $errors = [];
        $year = $data['year_label'] ?? null;
        if (!is_string($year) || preg_match('/^\d{4}-\d{4}$/', $year) !== 1) {
            $errors['year_label'][] = 'Année scolaire attendue au format AAAA-AAAA.';
        }
        foreach (self::COUNTS as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                $errors[$field][] = 'Entier positif ou nul requis.';
            }
        }
        foreach (self::FLAGS as $field) {
            if (!is_bool($data[$field] ?? null)) {
                $errors[$field][] = 'Valeur booléenne requise.';
            }
        }
        $network = $data['network_observed'] ?? null;
        if ($network !== null && (!is_string($network) || !in_array($network, self::NETWORKS, true))) {
            $errors['network_observed'][] = 'Valeur attendue : ' . implode(', ', self::NETWORKS) . '.';
        }
        $equipment = self::equipment($data['digital_equipment'] ?? [], $errors);
        $classrooms = self::classrooms($data['classrooms'] ?? [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'year_label' => (string) $year,
            'desks_count' => (int) $data['desks_count'],
            'water_points' => (int) $data['water_points'],
            'latrines_girls' => (int) $data['latrines_girls'],
            'latrines_boys' => (int) $data['latrines_boys'],
            'has_electricity' => (bool) $data['has_electricity'],
            'has_fence' => (bool) $data['has_fence'],
            'has_canteen' => (bool) $data['has_canteen'],
            'has_library' => (bool) $data['has_library'],
            'network_observed' => is_string($network) ? $network : null,
            'digital_equipment' => $equipment,
            'classrooms' => $classrooms,
        ];
    }

    /** @param array<string, list<string>> $errors */
    private static function equipment(mixed $items, array &$errors): ?string
    {
        if ($items === null || $items === []) {
            return null;
        }
        if (!is_array($items) || !array_is_list($items)) {
            $errors['digital_equipment'][] = 'Liste d\'équipements attendue.';

            return null;
        }
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['label'] ?? null) || trim($item['label']) === '' || mb_strlen(trim($item['label'])) > 80
                || !is_int($item['quantity'] ?? null) || $item['quantity'] < 0) {
                $errors['digital_equipment'][] = 'Chaque équipement exige un libellé (80 caractères au plus) et une quantité entière positive ou nulle.';

                return null;
            }
            $clean[] = ['label' => trim($item['label']), 'quantity' => $item['quantity']];
        }

        return json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{label: string, material: string, room_state: string, in_use: bool}>
     */
    private static function classrooms(mixed $rooms, array &$errors): array
    {
        if (!is_array($rooms) || !array_is_list($rooms)) {
            $errors['classrooms'][] = 'Liste de salles attendue.';

            return [];
        }
        $clean = [];
        foreach ($rooms as $index => $room) {
            if (!is_array($room) || !is_string($room['label'] ?? null) || trim($room['label']) === '' || mb_strlen(trim($room['label'])) > 60
                || !is_string($room['material'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,19}$/', $room['material']) !== 1
                || !is_string($room['room_state'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,15}$/', $room['room_state']) !== 1
                || !is_bool($room['in_use'] ?? null)) {
                $errors['classrooms.' . $index][] = 'Salle invalide : libellé (60 caractères), matériau et état en minuscules, en_usage booléen (in_use).';

                continue;
            }
            $clean[] = ['label' => trim($room['label']), 'material' => $room['material'], 'room_state' => $room['room_state'], 'in_use' => $room['in_use']];
        }

        return $clean;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
