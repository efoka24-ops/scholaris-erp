<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Fiche de l'élève dans l'école (ELV) : acte de naissance, situations particulières (EX-ELV-04) et liste trimestrielle des élèves
 * sans acte (RG-ELV-2, DOC-07). Une école ne modifie la fiche que d'un élève qu'elle scolarise : après un transfert, l'école d'origine
 * la garde en lecture seule (RG-ELV-5). Aucun champ APEE (RG-ELV-1), aucun diagnostic (type d'aménagement seulement).
 */
final class PupilProfileService
{
    /** Statuts d'une inscription vivante (une seule à la fois, RG-ELV-4). */
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL, 'suspended'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly PupilClock $clock,
    ) {
    }

    /** Vrai si l'école a scolarisé l'élève (inscription en cours ou close) : elle peut alors lire sa fiche. */
    public function knownHere(string $tenantId, string $establishmentId, string $personId): bool
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId) {
                return true;
            }
        }

        return false;
    }

    /** Inscription en cours de l'élève dans cette école, ou null (fiche en lecture seule). */
    public function liveEnrollment(string $tenantId, string $establishmentId, string $personId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true)) {
                return $enrollment;
            }
        }

        return null;
    }

    /**
     * Fiche de l'élève telle que la voit l'école ; les situations particulières ne sont rendues que si `$withSituations`.
     *
     * @return array<string, mixed>|null
     */
    public function show(string $tenantId, string $establishmentId, string $personId, bool $withSituations): ?array
    {
        if (!$this->knownHere($tenantId, $establishmentId, $personId)) {
            return null;
        }
        $person = $this->people->find($tenantId, $personId);
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_pupil_registrations WHERE tenant_id = ? AND person_id = ?');
        $query->execute([$tenantId, $personId]);
        $registration = $query->fetch(PDO::FETCH_ASSOC);
        if ($person === null || !is_array($registration)) {
            return null;
        }
        $profile = $this->profile($tenantId, $personId);
        $live = $this->liveEnrollment($tenantId, $establishmentId, $personId);
        $data = [
            'person_id' => $personId,
            'learner_number' => $registration['learner_number'] !== null ? (string) $registration['learner_number'] : null,
            'last_name' => $person->lastName,
            'first_name' => $person->firstName,
            'birth_date' => (string) $registration['birth_date'],
            'sex' => (string) $registration['sex'],
            'structure_id' => $live?->structureId,
            'enrollment_status' => $live !== null ? $live->status : 'closed',
            'read_only' => $live === null,
            'birth_act_ref' => $profile['birth_act_ref'] ?? null,
            'birth_act_verified' => (bool) ($profile['birth_act_verified'] ?? false),
            'registered_on' => (string) $registration['registered_on'],
            'registration_establishment_id' => (string) $registration['establishment_id'],
        ];
        if ($withSituations) {
            $data['situations'] = [
                'home_language' => $profile['home_language'] ?? null,
                'is_displaced' => (bool) ($profile['is_displaced'] ?? false),
                'is_refugee' => (bool) ($profile['is_refugee'] ?? false),
                'is_orphan' => (bool) ($profile['is_orphan'] ?? false),
                'accommodation_type' => $profile['accommodation_type'] ?? null,
            ];
        }

        return $data;
    }

    /**
     * Met à jour l'acte de naissance et les situations particulières (champs absents inchangés).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $establishmentId, string $personId, array $data): array
    {
        if (!$this->knownHere($tenantId, $establishmentId, $personId)) {
            throw HttpException::notFound('Élève introuvable dans cette école.');
        }
        if ($this->liveEnrollment($tenantId, $establishmentId, $personId) === null) {
            throw HttpException::conflict('L\'élève n\'est plus scolarisé dans cette école : sa fiche est en lecture seule.');
        }
        $current = $this->profile($tenantId, $personId);
        $merged = array_replace($current ?? [], array_intersect_key($data, array_flip(['birth_act_ref', 'birth_act_verified', 'home_language', 'is_displaced', 'is_refugee', 'is_orphan', 'accommodation_type'])));
        $errors = [];
        $ref = self::nullableText($merged['birth_act_ref'] ?? null, 80, 'birth_act_ref', $errors);
        $verified = $merged['birth_act_verified'] ?? false;
        $verified = is_bool($verified) ? $verified : (is_int($verified) && ($verified === 0 || $verified === 1) ? $verified === 1 : null);
        if ($verified === null) {
            $errors['birth_act_verified'][] = 'Valeur booléenne requise.';
        } elseif ($verified && $ref === null) {
            $errors['birth_act_verified'][] = 'Un acte ne peut être vérifié sans référence.';
        }
        $language = self::nullableText($merged['home_language'] ?? null, 40, 'home_language', $errors);
        $flags = [];
        foreach (['is_displaced', 'is_refugee', 'is_orphan'] as $flag) {
            $value = $merged[$flag] ?? false;
            $value = is_bool($value) ? $value : (is_int($value) && ($value === 0 || $value === 1) ? $value === 1 : null);
            if ($value === null) {
                $errors[$flag][] = 'Valeur booléenne requise.';
            }
            $flags[$flag] = $value === true;
        }
        $accommodation = self::nullableText($merged['accommodation_type'] ?? null, 40, 'accommodation_type', $errors);
        if ($accommodation !== null && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $accommodation) !== 1) {
            $errors['accommodation_type'][] = 'Type d\'aménagement attendu (code en minuscules) : jamais de diagnostic.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $pdo = $this->connections->pdo();
        $now = $this->clock->now();
        if ($current === null) {
            $pdo->prepare(
                'INSERT INTO pub_pupil_profiles (id, tenant_id, person_id, birth_act_ref, birth_act_verified, home_language, is_displaced, is_refugee, is_orphan, accommodation_type, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $personId, $ref, (int) $verified, $language, (int) $flags['is_displaced'], (int) $flags['is_refugee'], (int) $flags['is_orphan'], $accommodation, $now]);
        } else {
            $pdo->prepare(
                'UPDATE pub_pupil_profiles SET birth_act_ref = ?, birth_act_verified = ?, home_language = ?, is_displaced = ?, is_refugee = ?, is_orphan = ?, accommodation_type = ?, updated_at = ? '
                . 'WHERE tenant_id = ? AND person_id = ?',
            )->execute([$ref, (int) $verified, $language, (int) $flags['is_displaced'], (int) $flags['is_refugee'], (int) $flags['is_orphan'], $accommodation, $now, $tenantId, $personId]);
        }

        return $this->show($tenantId, $establishmentId, $personId, true) ?? throw new \LogicException('Fiche élève indisponible après écriture.');
    }

    /**
     * Élèves scolarisés sans référence d'acte de naissance (DOC-07, RG-ELV-2), triés par nom.
     *
     * @return list<array<string, mixed>>
     */
    public function withoutBirthAct(string $tenantId, string $establishmentId): array
    {
        $rows = [];
        foreach ($this->enrollments->personIdsInEstablishment($tenantId, $establishmentId) as $personId) {
            $profile = $this->profile($tenantId, $personId);
            if (($profile['birth_act_ref'] ?? null) !== null && $profile['birth_act_ref'] !== '') {
                continue;
            }
            $person = $this->people->find($tenantId, $personId);
            if ($person === null) {
                continue;
            }
            $query = $this->connections->pdo()->prepare('SELECT learner_number, registered_on FROM pub_pupil_registrations WHERE tenant_id = ? AND person_id = ?');
            $query->execute([$tenantId, $personId]);
            $registration = $query->fetch(PDO::FETCH_ASSOC);
            $rows[] = [
                'person_id' => $personId,
                'last_name' => $person->lastName,
                'first_name' => $person->firstName,
                'learner_number' => is_array($registration) && $registration['learner_number'] !== null ? (string) $registration['learner_number'] : null,
                'registered_on' => is_array($registration) ? (string) $registration['registered_on'] : null,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => [$left['last_name'], $left['first_name'], $left['person_id']] <=> [$right['last_name'], $right['first_name'], $right['person_id']]);

        return $rows;
    }

    /** Trimestre civil courant au format AAAA-Tn. */
    public function currentQuarter(): string
    {
        $moment = $this->clock->moment();

        return $moment->format('Y') . '-T' . (string) (intdiv((int) $moment->format('n') - 1, 3) + 1);
    }

    /**
     * Enregistre la liste trimestrielle transmise à l'inspection (une par école et par trimestre ; une nouvelle transmission la met à jour).
     *
     * @return array<string, mixed>
     */
    public function transmitList(string $tenantId, string $establishmentId, string $quarter, string $actorId): array
    {
        if (preg_match('/^\d{4}-T[1-4]$/', $quarter) !== 1) {
            throw HttpException::validation(['quarter' => ['Trimestre attendu au format AAAA-Tn (n de 1 à 4).']]);
        }
        $count = count($this->withoutBirthAct($tenantId, $establishmentId));
        $today = $this->clock->today();
        $pdo = $this->connections->pdo();
        $existing = $pdo->prepare('SELECT id FROM pub_birth_act_lists WHERE tenant_id = ? AND establishment_id = ? AND quarter_label = ?');
        $existing->execute([$tenantId, $establishmentId, $quarter]);
        $id = $existing->fetchColumn();
        if (is_string($id)) {
            $pdo->prepare('UPDATE pub_birth_act_lists SET pupil_count = ?, generated_by = ?, generated_on = ?, transmitted_on = ? WHERE tenant_id = ? AND id = ?')
                ->execute([$count, $actorId, $today, $today, $tenantId, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO pub_birth_act_lists (id, tenant_id, establishment_id, quarter_label, pupil_count, generated_by, generated_on, transmitted_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $quarter, $count, $actorId, $today, $today]);
        }

        return ['quarter' => $quarter, 'pupil_count' => $count, 'generated_on' => $today, 'transmitted_on' => $today];
    }

    /** @return list<array<string, mixed>> */
    public function transmissions(string $tenantId, string $establishmentId): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT quarter_label, pupil_count, generated_by, generated_on, transmitted_on FROM pub_birth_act_lists WHERE tenant_id = ? AND establishment_id = ? ORDER BY quarter_label',
        );
        $query->execute([$tenantId, $establishmentId]);

        return array_values(array_map(static fn (array $row): array => [
            'quarter' => (string) $row['quarter_label'],
            'pupil_count' => (int) $row['pupil_count'],
            'generated_by' => (string) $row['generated_by'],
            'generated_on' => (string) $row['generated_on'],
            'transmitted_on' => $row['transmitted_on'] !== null ? (string) $row['transmitted_on'] : null,
        ], $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return array<string, mixed>|null */
    private function profile(string $tenantId, string $personId): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_pupil_profiles WHERE tenant_id = ? AND person_id = ?');
        $query->execute([$tenantId, $personId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }
        foreach (['birth_act_verified', 'is_displaced', 'is_refugee', 'is_orphan'] as $flag) {
            $row[$flag] = (bool) $row[$flag];
        }

        return $row;
    }

    /** @param array<string, list<string>> $errors */
    private static function nullableText(mixed $value, int $max, string $field, array &$errors): ?string
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
}
