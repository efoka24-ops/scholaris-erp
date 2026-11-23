<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Schools\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

/**
 * Fiche de l'école publique (EX-ECO-01) : code officiel, acte de création, sous-système, cycle, groupe scolaire, statut, position
 * GPS et contacts. RG-TER-5 : une école sans référence d'acte de création n'entre pas dans les statistiques officielles.
 */
final class SchoolFicheService
{
    public const SUBSYSTEMS = ['francophone', 'anglophone', 'bilingual'];
    public const CYCLES = ['nursery', 'primary', 'group'];
    public const STATUSES = ['open', 'closed', 'suspended'];

    private const FIELDS = [
        'official_code', 'creation_act_ref', 'creation_act_date', 'subsystem', 'cycle', 'school_group_ref', 'status', 'latitude', 'longitude', 'phone', 'email',
    ];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_schools WHERE tenant_id = ? AND establishment_id = ?');
        $query->execute([$tenantId, $establishmentId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    /**
     * Crée ou met à jour la fiche (les champs absents de `$data` restent inchangés).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(string $tenantId, string $establishmentId, array $data): array
    {
        $current = $this->find($tenantId, $establishmentId);
        $merged = array_replace($current ?? [], array_intersect_key($data, array_flip(self::FIELDS)));
        if (($data['new_group'] ?? false) === true) {
            $merged['school_group_ref'] = (string) new Ulid();
        }
        $values = self::validated($merged);
        $this->assertCodeFree($tenantId, $establishmentId, $values['official_code']);
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $pdo = $this->connections->pdo();
        if ($current === null) {
            $pdo->prepare(
                'INSERT INTO pub_schools (id, tenant_id, establishment_id, official_code, creation_act_ref, creation_act_date, subsystem, cycle, school_group_ref, status, latitude, longitude, phone, email, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                (string) new Ulid(), $tenantId, $establishmentId, $values['official_code'], $values['creation_act_ref'], $values['creation_act_date'],
                $values['subsystem'], $values['cycle'], $values['school_group_ref'], $values['status'], $values['latitude'], $values['longitude'],
                $values['phone'], $values['email'], $now, $now,
            ]);
        } else {
            $pdo->prepare(
                'UPDATE pub_schools SET official_code = ?, creation_act_ref = ?, creation_act_date = ?, subsystem = ?, cycle = ?, school_group_ref = ?, status = ?, '
                . 'latitude = ?, longitude = ?, phone = ?, email = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ?',
            )->execute([
                $values['official_code'], $values['creation_act_ref'], $values['creation_act_date'], $values['subsystem'], $values['cycle'],
                $values['school_group_ref'], $values['status'], $values['latitude'], $values['longitude'], $values['phone'], $values['email'],
                $now, $tenantId, $establishmentId,
            ]);
        }

        return $this->find($tenantId, $establishmentId) ?? throw new \LogicException('Fiche école indisponible après écriture.');
    }

    /**
     * RG-TER-5 : parmi les établissements donnés, ceux dont l'école a une référence d'acte de création (seuls comptés dans les
     * statistiques officielles).
     *
     * @param list<string> $establishmentIds
     * @return list<string>
     */
    public function officialAmong(string $tenantId, array $establishmentIds): array
    {
        $official = [];
        foreach ($establishmentIds as $establishmentId) {
            $fiche = $this->find($tenantId, $establishmentId);
            if ($fiche !== null && $fiche['official'] === true) {
                $official[] = $establishmentId;
            }
        }

        return $official;
    }

    private function assertCodeFree(string $tenantId, string $establishmentId, string $code): void
    {
        $query = $this->connections->pdo()->prepare('SELECT establishment_id FROM pub_schools WHERE tenant_id = ? AND official_code = ?');
        $query->execute([$tenantId, $code]);
        $owner = $query->fetchColumn();
        if ($owner !== false && $owner !== $establishmentId) {
            throw HttpException::conflict('Ce code officiel est déjà porté par une autre école.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{official_code: string, creation_act_ref: ?string, creation_act_date: ?string, subsystem: string, cycle: string, school_group_ref: ?string, status: string, latitude: ?string, longitude: ?string, phone: ?string, email: ?string}
     */
    private static function validated(array $data): array
    {
        $errors = [];
        $code = $data['official_code'] ?? null;
        if (!is_string($code) || trim($code) === '' || mb_strlen(trim($code)) > 40) {
            $errors['official_code'][] = 'Code officiel requis (40 caractères au plus).';
        }
        foreach (['subsystem' => self::SUBSYSTEMS, 'cycle' => self::CYCLES, 'status' => self::STATUSES] as $field => $allowed) {
            if (!is_string($data[$field] ?? null) || !in_array($data[$field], $allowed, true)) {
                $errors[$field][] = 'Valeur attendue : ' . implode(', ', $allowed) . '.';
            }
        }
        $ref = self::nullableString($data['creation_act_ref'] ?? null, 120, 'creation_act_ref', $errors);
        $actDate = self::nullableString($data['creation_act_date'] ?? null, 10, 'creation_act_date', $errors);
        if ($actDate !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $actDate);
            if ($parsed === false || $parsed->format('Y-m-d') !== $actDate) {
                $errors['creation_act_date'][] = 'Date AAAA-MM-JJ attendue.';
            } elseif ($ref === null) {
                $errors['creation_act_ref'][] = 'La référence de l\'acte est requise avec sa date.';
            }
        }
        $group = $data['school_group_ref'] ?? null;
        if ($group !== null && (!is_string($group) || !Ulid::isValid($group))) {
            $errors['school_group_ref'][] = 'Référence de groupe scolaire invalide.';
        }
        [$latitude, $longitude] = self::position($data['latitude'] ?? null, $data['longitude'] ?? null, $errors);
        $phone = self::nullableString($data['phone'] ?? null, 30, 'phone', $errors);
        if ($phone !== null && preg_match('/^[0-9+() .-]{6,30}$/', $phone) !== 1) {
            $errors['phone'][] = 'Numéro de téléphone invalide.';
        }
        $email = self::nullableString($data['email'] ?? null, 160, 'email', $errors);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Adresse e-mail invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'official_code' => trim((string) $code),
            'creation_act_ref' => $ref,
            'creation_act_date' => $actDate,
            'subsystem' => (string) $data['subsystem'],
            'cycle' => (string) $data['cycle'],
            'school_group_ref' => is_string($group) ? $group : null,
            'status' => (string) $data['status'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'phone' => $phone,
            'email' => $email,
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array{0: ?string, 1: ?string}
     */
    private static function position(mixed $latitude, mixed $longitude, array &$errors): array
    {
        if ($latitude === null && $longitude === null) {
            return [null, null];
        }
        if (!is_numeric($latitude) || !is_numeric($longitude) || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
            $errors['latitude'][] = 'Position GPS invalide : latitude et longitude numériques attendues ensemble.';

            return [null, null];
        }

        return [self::decimal($latitude), self::decimal($longitude)];
    }

    private static function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }

    /** @param array<string, list<string>> $errors */
    private static function nullableString(mixed $value, int $max, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = 'Texte de ' . $max . ' caractères au plus attendu.';

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $row['official'] = is_string($row['creation_act_ref'] ?? null) && trim((string) $row['creation_act_ref']) !== '';

        return $row;
    }
}
