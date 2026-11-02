<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure\PdoStudentRegistry;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\FamilyNotifications;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Inscription au secondaire (T824 à T826, API-01, API-02, US3) : fiche de l'élève et garants (socle, LearnerWriter), inscription
 * du socle dans la classe (gardes `organisation.enrolment.guards.*`, dont la garde du secondaire), dossier secondaire, trace de
 * l'inscription (alerte d'âge, décisions), matricule national tenu par CarteScolaire (contrat NationalIds).
 *  - RG-INS-1 : sans matricule, l'élève est « en attente » et figure dans la liste à régulariser ;
 *  - RG-INS-2 : âge hors des limites du règlement intérieur (`admission_age_limits`, vide = aucune alerte) : alerte, et décision
 *    motivée du chef d'établissement (`secondary.enrol.validate`) exigée ;
 *  - RG-INS-3 : exclu définitif : décision motivée du chef exigée, enregistrée pour l'année ;
 *  - RG-INS-4 : aucune vérification de paiement, jamais (public comme privé : le blocage du privé porte sur les documents).
 */
final class EnrolmentService
{
    public const READ = ['secondary.enrol.read', 'secondary.enrol.manage', 'secondary.enrol.validate', 'secondary.settings.manage'];
    public const MANAGE = ['secondary.enrol.manage'];
    public const DECIDE = ['secondary.enrol.validate'];
    public const AGE_ALERT = 'https://api.scholaris.cm/errors/admission-age-alert';
    public const EXCLUDED = 'https://api.scholaris.cm/errors/final-exclusion';
    public const ENTRY_KINDS = ['new', 'reenrolment', 'transfer_in', 'primary_entry', 'import'];
    private const IDENTITY = ['last_name', 'first_name', 'birth_date', 'birth_place', 'gender', 'phone', 'email', 'locale', 'nationality'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentProfiles $profiles,
        private readonly ClassProfiles $classes,
        private readonly SchoolRules $rules,
        private readonly PdoStudentRegistry $students,
        private readonly NationalIds $nationalIds,
        private readonly LearnerWriter $learners,
        private readonly PersonDirectory $people,
        private readonly BirthInfo $births,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly SecondaryEnrolmentGuard $guard,
        private readonly SecondaryClock $clock,
        private readonly FamilyNotifications $notifications,
    ) {
    }

    /**
     * API-01 : inscrit un élève (existant ou nouveau) dans une classe du secondaire.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function enrol(Principal $principal, string $establishmentId, array $body): array
    {
        $errors = [];
        $allowed = ['person_id', 'identity', 'guardians', 'class_id', 'entry_date', 'national_id', 'repeater', 'entry_kind', 'age_decision_reason', 'exclusion_decision_reason', 'documents_checked', 'confirm_different', 'special_situation'];
        foreach (array_keys($body) as $field) {
            if (!in_array($field, $allowed, true)) {
                $errors[(string) $field] = ['Champ non reconnu.'];
            }
        }
        $classId = is_string($body['class_id'] ?? null) ? $body['class_id'] : '';
        if ($classId === '') {
            $errors['class_id'] = ['Classe obligatoire.'];
        }
        $date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';
        if (!self::isDate($date)) {
            $errors['entry_date'] = ['Date d\'entrée au format AAAA-MM-JJ attendue.'];
        }
        $personId = is_string($body['person_id'] ?? null) && $body['person_id'] !== '' ? $body['person_id'] : null;
        $identity = is_array($body['identity'] ?? null) ? $body['identity'] : null;
        if ($personId === null && $identity === null) {
            $errors['person_id'] = ['Indiquez un élève existant (person_id) ou son identité (identity).'];
        }
        $kind = $body['entry_kind'] ?? 'new';
        if (!is_string($kind) || !in_array($kind, self::ENTRY_KINDS, true)) {
            $errors['entry_kind'] = ['Valeurs possibles : ' . implode(', ', self::ENTRY_KINDS) . '.'];
        }
        $documents = $body['documents_checked'] ?? [];
        if (!is_array($documents) || !array_is_list($documents) || count($documents) > 20 || array_filter($documents, static fn (mixed $d): bool => !is_string($d) || mb_strlen($d) > 80) !== []) {
            $errors['documents_checked'] = ['Liste de 20 pièces au plus (textes de 80 caractères).'];
        }
        $guardians = $body['guardians'] ?? [];
        if (!is_array($guardians) || !array_is_list($guardians)) {
            $errors['guardians'] = ['Liste de garants attendue.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        /** @var list<array{kind: string, person_id?: string, identity?: array<string, mixed>}> $guardians */
        $plan = [
            'person_id' => $personId, 'identity' => $identity, 'guardians' => $guardians, 'class_id' => $classId, 'entry_date' => $date,
            'national_id' => is_string($body['national_id'] ?? null) && trim($body['national_id']) !== '' ? $body['national_id'] : null,
            'repeater' => ($body['repeater'] ?? false) === true, 'entry_kind' => (string) $kind,
            'age_decision_reason' => self::text($body['age_decision_reason'] ?? null), 'exclusion_decision_reason' => self::text($body['exclusion_decision_reason'] ?? null),
            'documents_checked' => $documents, 'confirm_different' => ($body['confirm_different'] ?? false) === true,
            'special_situation' => self::text($body['special_situation'] ?? null),
        ];

        return $this->admit($principal, $establishmentId, $plan);
    }

    /**
     * Cœur commun de l'inscription (API-01, conversion de préinscription, réinscription, transfert entrant, import).
     *
     * @param array<string, mixed> $plan voir enrol()
     * @return array<string, mixed>
     */
    public function admit(Principal $principal, string $establishmentId, array $plan, bool $dryRun = false): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        if (!$this->profiles->forEstablishment($tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
        $class = $this->classProfile($tenantId, $establishmentId, (string) $plan['class_id']);
        $this->access->assertWritableClass($principal, $perimeter, $class->structureId, 'se_class_profile', $class->id);
        $personId = is_string($plan['person_id'] ?? null) ? $plan['person_id'] : null;
        $identity = is_array($plan['identity'] ?? null) ? array_intersect_key($plan['identity'], array_flip(self::IDENTITY)) : [];
        if ($personId !== null && $this->people->find($tenantId, $personId) === null) {
            throw HttpException::validation(['person_id' => ['Élève introuvable dans ce client.']]);
        }
        $birth = $personId !== null ? $this->births->birthDate($tenantId, $personId) : (is_string($identity['birth_date'] ?? null) ? $identity['birth_date'] : null);
        $date = (string) $plan['entry_date'];

        $nationalId = null;
        if (is_string($plan['national_id'] ?? null)) {
            $nationalId = $this->nationalIds->normalize($tenantId, $establishmentId, $plan['national_id']);
            if ($nationalId === null) {
                throw HttpException::validation(['national_id' => ['Matricule national au format attendu par le paramètre de l\'établissement.']]);
            }
            $owner = $this->nationalIds->personIdByNationalId($tenantId, $establishmentId, $nationalId);
            if ($owner !== null && $owner !== $personId) {
                throw HttpException::conflict('Ce matricule national est déjà attribué à un autre élève de l\'établissement.');
            }
        }

        $ageReason = is_string($plan['age_decision_reason'] ?? null) ? $plan['age_decision_reason'] : null;
        $ageAlert = $this->ageAlert($tenantId, $establishmentId, $class->levelCode, $birth, $date);
        if ($ageAlert !== null && $ageReason === null) {
            throw new HttpException(422, 'Alerte d\'âge', $ageAlert . ' La décision motivée du chef d\'établissement est exigée (RG-INS-2) : renseignez age_decision_reason.', self::AGE_ALERT);
        }
        $exclusionReason = is_string($plan['exclusion_decision_reason'] ?? null) ? $plan['exclusion_decision_reason'] : null;
        $student = $personId === null ? null : $this->students->profile($tenantId, $establishmentId, $personId);
        $excluded = $student !== null && $student->finalExclusionOn !== null && $student->readmissionYearId !== $class->academicYearId;
        if ($excluded && $exclusionReason === null) {
            throw new HttpException(422, 'Élève exclu définitivement', 'Cet élève a été exclu définitivement de l\'établissement : la réinscription exige une décision motivée du chef d\'établissement (RG-INS-3). Renseignez exclusion_decision_reason.', self::EXCLUDED);
        }
        if (($ageAlert !== null || $excluded) && $this->access->perimeter($principal, $establishmentId, self::DECIDE)->isEmpty()) {
            throw HttpException::forbidden('Décision réservée au chef d\'établissement (permission secondary.enrol.validate).');
        }
        $levelId = $this->tree->levelOf($tenantId, $establishmentId, $class->structureId);
        $refusal = $this->guard->check($tenantId, $establishmentId, $class->structureId, $levelId, $class->academicYearId, $excluded ? null : $personId, new DateTimeImmutable($date, new DateTimeZone('Africa/Douala')));
        if ($refusal !== null) {
            throw HttpException::validation(['class_id' => [$refusal]]);
        }
        if ($dryRun) {
            return ['age_alert' => $ageAlert, 'class_id' => $class->id, 'person_id' => $personId];
        }

        if ($personId === null) {
            /** @var list<array{kind: string, person_id?: string, identity?: array<string, mixed>}> $guardians */
            $guardians = is_array($plan['guardians'] ?? null) ? $plan['guardians'] : [];
            $personId = $this->learners->createLearner($tenantId, $identity, $guardians, $principal->userId, ($plan['confirm_different'] ?? false) === true)['person_id'];
        }
        if ($excluded) {
            $this->students->upsert($tenantId, $establishmentId, $personId, ['readmission_year_id' => $class->academicYearId, 'readmission_reason' => $exclusionReason, 'readmission_decided_by' => $principal->userId]);
        }
        $enrollmentId = $this->learners->enroll($tenantId, $establishmentId, $personId, $class->structureId, $class->academicYearId, $date, $principal->userId);
        $changes = ['repeater' => ($plan['repeater'] ?? false) === true ? 1 : 0];
        if (is_string($plan['special_situation'] ?? null)) {
            $changes['special_situation'] = $plan['special_situation'];
        }
        $this->students->upsert($tenantId, $establishmentId, $personId, $changes);
        if ($nationalId !== null) {
            $this->nationalIds->record($tenantId, $establishmentId, $personId, $nationalId, 'enrolment', $principal->userId);
        }
        $existing = $this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['enrollment_id' => $enrollmentId], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $this->store->insert('se_enrolment_records', $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'person_id' => $personId, 'enrollment_id' => $enrollmentId, 'class_structure_id' => $class->structureId,
                'class_profile_id' => $class->id, 'level_code' => $class->levelCode, 'academic_year_id' => $class->academicYearId,
                'entry_kind' => (string) ($plan['entry_kind'] ?? 'new'), 'entry_date' => $date, 'repeater' => $changes['repeater'],
                'age_alert' => $ageAlert === null ? 0 : 1, 'age_decision_reason' => $ageAlert === null ? null : $ageReason,
                'exclusion_decision_reason' => $excluded ? $exclusionReason : null, 'decided_by' => ($ageAlert !== null || $excluded) ? $principal->userId : null,
                'documents_checked' => json_encode(array_values((array) ($plan['documents_checked'] ?? [])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
            ]);
        }
        $this->access->audit($principal, 'secondary.enrolment.created', 'se_enrolment_record', $enrollmentId, null, [
            'person_id' => $personId, 'class_id' => $class->id, 'age_alert' => $ageAlert !== null, 'readmission' => $excluded,
        ]);
        $this->notifyValidated($tenantId, $establishmentId, $personId, $enrollmentId);

        return $this->dossier($tenantId, $establishmentId, $personId);
    }

    /**
     * NOT-01 « inscription validée, matricule enregistré » : seulement quand les deux sont vrais. Le service de notification ne
     * notifie jamais deux fois ; un échec d'envoi ne remet pas en cause l'inscription.
     */
    private function notifyValidated(string $tenantId, string $establishmentId, string $personId, string $enrollmentId): void
    {
        if ($this->nationalIds->of($tenantId, $establishmentId, $personId) === null) {
            return;
        }
        try {
            $this->notifications->enrolmentValidated($tenantId, $establishmentId, $enrollmentId);
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * API-02 : enregistre le matricule national (façade du contrat CarteScolaire\NationalIds : format paramétré, unicité).
     *
     * @param array<string, mixed> $body national_id
     * @return array<string, mixed>
     */
    public function setNationalId(Principal $principal, string $establishmentId, string $personId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $record = $this->latestRecord($tenantId, $establishmentId, $personId) ?? throw HttpException::notFound('Élève introuvable dans cet établissement.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $record['class_structure_id'], 'se_student_profile', $personId);
        $unknown = array_diff(array_keys($body), ['national_id']);
        $value = $body['national_id'] ?? null;
        if ($unknown !== [] || !is_string($value) || trim($value) === '') {
            throw HttpException::validation(['national_id' => ['Matricule national obligatoire (seul champ accepté).']]);
        }
        $this->nationalIds->record($tenantId, $establishmentId, $personId, $value, 'manual', $principal->userId);
        $this->access->audit($principal, 'secondary.national_id.recorded', 'se_student_profile', $personId, null, ['status' => 'recorded']);
        $this->notifyValidated($tenantId, $establishmentId, $personId, (string) $record['enrollment_id']);

        return $this->dossier($tenantId, $establishmentId, $personId);
    }

    /**
     * Élèves d'une année (ou de toutes), avec classe, statut du matricule, alerte d'âge ; périmètre classe appliqué.
     *
     * @return list<array<string, mixed>>
     */
    public function students(Principal $principal, string $establishmentId, ?string $academicYearId, ?string $classStructureId, bool $pendingOnly): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $where = [];
        if ($academicYearId !== null) {
            $where['academic_year_id'] = $academicYearId;
        }
        if ($classStructureId !== null) {
            $where['class_structure_id'] = $classStructureId;
        }
        $rows = array_values(array_filter(
            $this->store->select('se_enrolment_records', $tenantId, $establishmentId, $where, 'academic_year_id, level_code, created_at', 5000),
            static fn (array $row): bool => $perimeter->coversClass((string) $row['class_structure_id']),
        ));
        $names = [];
        foreach ($this->people->findMany($tenantId, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows)))) as $person) {
            $names[$person->id] = $person;
        }
        $result = [];
        foreach ($rows as $row) {
            $personId = (string) $row['person_id'];
            $status = $this->nationalIds->statusOf($tenantId, $establishmentId, $personId);
            if ($pendingOnly && $status !== 'pending') {
                continue;
            }
            $person = $names[$personId] ?? null;
            $result[] = self::record($row) + [
                'last_name' => $person?->lastName, 'first_name' => $person?->firstName, 'gender' => $person?->gender, 'national_id_status' => $status,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function student(Principal $principal, string $establishmentId, string $personId): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $record = $this->latestRecord($tenantId, $establishmentId, $personId) ?? throw HttpException::notFound('Élève introuvable dans cet établissement.');
        $this->access->assertReadableClass($perimeter, (string) $record['class_structure_id']);

        return $this->dossier($tenantId, $establishmentId, $personId);
    }

    /** @return array<string, mixed>|null */
    public function latestRecord(string $tenantId, string $establishmentId, string $personId): ?array
    {
        return $this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['person_id' => $personId], 'created_at DESC', 1)[0] ?? null;
    }

    public function classProfile(string $tenantId, string $establishmentId, string $classId): ClassProfileInfo
    {
        $row = $this->store->find('se_class_profiles', $tenantId, $establishmentId, $classId);
        $class = $row === null ? null : $this->classes->find($tenantId, $establishmentId, (string) $row['structure_id']);
        if ($class === null || $class->id !== $classId) {
            throw HttpException::validation(['class_id' => ['Classe introuvable dans le paramétrage de l\'établissement.']]);
        }

        return $class;
    }

    /**
     * Dossier de l'élève (EX-SEC-15) : identité courte, statut du matricule (jamais dans l'adresse), redoublement, exclusion,
     * inscriptions de l'établissement, traces de l'application, transferts.
     *
     * @return array<string, mixed>
     */
    public function dossier(string $tenantId, string $establishmentId, string $personId): array
    {
        $person = $this->people->find($tenantId, $personId);
        $profile = $this->students->row($tenantId, $establishmentId, $personId) ?? [];
        $enrollments = array_values(array_filter($this->enrollments->forPerson($tenantId, $personId), static fn ($e): bool => $e->establishmentId === $establishmentId));
        $records = $this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['person_id' => $personId], 'created_at', 100);
        $transfers = $this->store->select('se_transfers', $tenantId, $establishmentId, ['person_id' => $personId], 'created_at', 100);

        return [
            'person_id' => $personId,
            'last_name' => $person?->lastName, 'first_name' => $person?->firstName, 'gender' => $person?->gender,
            'birth_date' => $this->births->birthDate($tenantId, $personId),
            'national_id' => $this->nationalIds->of($tenantId, $establishmentId, $personId),
            'national_id_status' => $this->nationalIds->statusOf($tenantId, $establishmentId, $personId),
            'repeater' => (int) ($profile['repeater'] ?? 0) === 1,
            'final_exclusion_on' => $profile['final_exclusion_on'] ?? null,
            'readmission_year_id' => $profile['readmission_year_id'] ?? null,
            'special_situation' => $profile['special_situation'] ?? null,
            'enrollments' => array_map(static fn ($e): array => ['id' => $e->id, 'structure_id' => $e->structureId, 'academic_year_id' => $e->academicYearId, 'status' => $e->status, 'internal_number' => $e->internalNumber], $enrollments),
            'records' => array_map(self::record(...), $records),
            'transfers' => array_map(TransferService::resource(...), $transfers),
        ];
    }

    /**
     * Alerte RG-INS-2 : âge révolu à la date d'entrée hors des bornes `min_age` / `max_age` du niveau (règlement intérieur).
     * Aucune borne paramétrée : aucune alerte (Q2).
     */
    public function ageAlert(string $tenantId, string $establishmentId, string $levelCode, ?string $birthDate, string $on): ?string
    {
        $limits = $this->rules->value($tenantId, $establishmentId, 'admission_age_limits');
        $level = is_array($limits) && is_array($limits[$levelCode] ?? null) ? $limits[$levelCode] : null;
        if ($level === null) {
            return null;
        }
        if ($birthDate === null || !self::isDate($birthDate)) {
            return 'Date de naissance inconnue : l\'âge d\'admission ne peut pas être contrôlé.';
        }
        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($on))->y;
        $min = $level['min_age'] ?? null;
        $max = $level['max_age'] ?? null;
        if (is_int($min) && $age < $min) {
            return sprintf('Âge (%d ans) inférieur à l\'âge d\'admission paramétré pour ce niveau.', $age);
        }
        if (is_int($max) && $age > $max) {
            return sprintf('Âge (%d ans) supérieur à l\'âge d\'admission paramétré pour ce niveau.', $age);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function record(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'enrollment_id' => (string) $row['enrollment_id'],
            'class_structure_id' => (string) $row['class_structure_id'], 'class_id' => (string) $row['class_profile_id'], 'level_code' => (string) $row['level_code'],
            'academic_year_id' => (string) $row['academic_year_id'], 'entry_kind' => (string) $row['entry_kind'], 'entry_date' => (string) $row['entry_date'],
            'repeater' => (int) $row['repeater'] === 1, 'age_alert' => (int) $row['age_alert'] === 1, 'age_decision_reason' => $row['age_decision_reason'],
            'exclusion_decision_reason' => $row['exclusion_decision_reason'], 'decided_by' => $row['decided_by'],
            'documents_checked' => is_string($row['documents_checked']) ? json_decode($row['documents_checked'], true) : [],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** Motif lisible d'un refus (détail, puis première erreur par champ). */
    public static function explain(HttpException $e): string
    {
        foreach ($e->errors as $messages) {
            foreach ((array) $messages as $message) {
                return $e->getMessage() . ' ' . (string) $message;
            }
        }

        return $e->getMessage();
    }

    public static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if (mb_strlen(trim($value)) > 255) {
            throw HttpException::validation(['reason' => ['255 caractères au plus.']]);
        }

        return trim($value);
    }
}
