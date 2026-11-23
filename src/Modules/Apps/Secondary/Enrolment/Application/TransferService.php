<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;

/**
 * Transferts (T827, EX-SEC-17, INS-05) :
 *  - sortant : l'inscription vivante de l'établissement est close (`transferred`) à la date, exeat (document émis par Documents,
 *    identifiant opaque) et référence du dossier transmis enregistrés ; rien n'est supprimé ;
 *  - entrant : inscription dans une classe (mêmes contrôles qu'API-01) et contrôle du matricule déclaré par l'établissement
 *    d'origine : égal au matricule connu (`match`), absent ici (enregistré), ou différent (`mismatch`, à vérifier sur pièces).
 */
final class TransferService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EnrolmentService $enrolment,
        private readonly LearnerWriter $learners,
        private readonly EnrollmentDirectory $enrollments,
        private readonly NationalIds $nationalIds,
        private readonly SecondaryClock $clock,
        private readonly SecondaryDocuments $documents,
        private readonly ClassProfiles $classes,
    ) {
    }

    /**
     * @param array<string, mixed> $body person_id, other_school_label, transfer_date, exeat_document_id?, file_ref?, reason?
     * @return array<string, mixed>
     */
    public function transferOut(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, EnrolmentService::MANAGE);
        $in = new Input($body);
        $personId = (string) $in->reference('person_id');
        $school = (string) $in->string('other_school_label', 160);
        $date = (string) $in->date('transfer_date');
        $exeat = $in->reference('exeat_document_id', false, true);
        $file = $in->string('file_ref', 120, false, true);
        $reason = $in->string('reason', 255, false, true);
        $issueExeat = $in->bool('issue_exeat', false) ?? true;
        $yearLabel = $in->string('academic_year_label', 40, false, true);
        $in->done();
        $live = null;
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL, 'suspended'], true)) {
                $live = $enrollment;
            }
        }
        if ($live === null) {
            throw HttpException::notFound('Aucune inscription en cours de cet élève dans l\'établissement.');
        }
        $this->access->assertWritableClass($principal, $perimeter, $live->structureId, 'se_transfer', $personId);
        $this->learners->closeEnrollment($tenantId, $live->id, 'transferred', $date);
        $exeatError = null;
        if ($exeat === null && $issueExeat) {
            // DOC-05 : l'exeat s'émet APRÈS la clôture de l'inscription (contrat SecondaryDocuments) ; un refus n'annule pas le transfert.
            $class = $live->structureId === null ? null : $this->classes->find($tenantId, $establishmentId, $live->structureId);
            $level = $class === null ? null : $this->classes->level($tenantId, $establishmentId, $class->levelId);
            try {
                $exeat = $this->documents->issue($tenantId, $establishmentId, 'exeat', $personId, [
                    'academic_year' => $yearLabel ?? $live->academicYearId, 'class_name' => $class === null ? '' : $class->label, 'level_name' => is_array($level) ? (string) $level['label'] : '',
                    'destination' => $school, 'reason' => $reason ?? '', 'effective_on' => $date,
                ], $principal->userId)->id;
            } catch (HttpException $e) {
                $exeatError = EnrolmentService::explain($e);
            }
        }
        $id = $this->insert($principal, $establishmentId, [
            'person_id' => $personId, 'direction' => 'out', 'other_school_label' => $school, 'transfer_date' => $date, 'exeat_document_id' => $exeat,
            'national_id_checked' => 0, 'national_id_match' => null, 'file_ref' => $file, 'enrollment_id' => $live->id, 'reason' => $reason, 'status' => 'completed',
        ]);

        return self::resource($this->store->findOrFail('se_transfers', $tenantId, $establishmentId, $id)) + ['exeat_error' => $exeatError];
    }

    /**
     * @param array<string, mixed> $body other_school_label, transfer_date, declared_national_id?, file_ref?, exeat_document_id? + champs d'API-01
     * @return array<string, mixed>
     */
    public function transferIn(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $school = is_string($body['other_school_label'] ?? null) ? trim($body['other_school_label']) : '';
        if ($school === '' || mb_strlen($school) > 160) {
            $errors['other_school_label'] = ['Établissement d\'origine obligatoire (160 caractères au plus).'];
        }
        $declared = is_string($body['declared_national_id'] ?? null) && trim($body['declared_national_id']) !== '' ? $body['declared_national_id'] : null;
        $file = is_string($body['file_ref'] ?? null) ? mb_substr(trim($body['file_ref']), 0, 120) : null;
        $exeat = is_string($body['exeat_document_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $body['exeat_document_id']) === 1 ? $body['exeat_document_id'] : null;
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $known = is_string($body['person_id'] ?? null) ? $this->nationalIds->of($tenantId, $establishmentId, $body['person_id']) : null;
        $match = null;
        if ($declared !== null) {
            $clean = $this->nationalIds->normalize($tenantId, $establishmentId, $declared);
            $match = $clean === null ? 'invalid' : ($known === null ? 'recorded' : ($known === $clean ? 'match' : 'mismatch'));
        }
        $enrolBody = array_diff_key($body, array_flip(['other_school_label', 'declared_national_id', 'file_ref', 'exeat_document_id']));
        $enrolBody['entry_kind'] = 'transfer_in';
        if ($match === 'recorded') {
            $enrolBody['national_id'] = $declared;
        }
        $enrolBody['entry_date'] ??= $body['transfer_date'] ?? null;
        unset($enrolBody['transfer_date']);
        $dossier = $this->enrolment->enrol($principal, $establishmentId, $enrolBody);
        $enrollmentId = $dossier['records'] === [] ? null : (string) end($dossier['records'])['enrollment_id'];
        $id = $this->insert($principal, $establishmentId, [
            'person_id' => (string) $dossier['person_id'], 'direction' => 'in', 'other_school_label' => $school, 'transfer_date' => (string) $enrolBody['entry_date'],
            'exeat_document_id' => $exeat, 'national_id_checked' => $declared === null ? 0 : 1, 'national_id_match' => $match, 'file_ref' => $file,
            'enrollment_id' => $enrollmentId, 'reason' => null, 'status' => $match === 'mismatch' || $match === 'invalid' ? 'to_check' : 'completed',
        ]);

        return self::resource($this->store->findOrFail('se_transfers', $tenantId, $establishmentId, $id));
    }

    /** @param array<string, scalar|null> $values */
    private function insert(Principal $principal, string $establishmentId, array $values): string
    {
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_transfers', $principal->tenantId, $establishmentId, ['id' => $id] + $values + ['created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now]);
        $this->access->audit($principal, 'secondary.transfer.' . $values['direction'], 'se_transfer', $id, null, ['person_id' => $values['person_id'], 'status' => $values['status']]);

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'direction' => (string) $row['direction'],
            'other_school_label' => (string) $row['other_school_label'], 'transfer_date' => (string) $row['transfer_date'],
            'exeat_document_id' => $row['exeat_document_id'], 'national_id_checked' => (int) $row['national_id_checked'] === 1,
            'national_id_match' => $row['national_id_match'], 'file_ref' => $row['file_ref'], 'enrollment_id' => $row['enrollment_id'],
            'reason' => $row['reason'], 'status' => (string) $row['status'], 'created_at' => (string) $row['created_at'],
        ];
    }
}
