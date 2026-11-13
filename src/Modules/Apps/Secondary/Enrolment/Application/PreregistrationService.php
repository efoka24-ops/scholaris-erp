<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;

/**
 * Préinscriptions (T824, EX-SEC-13, INS-01) au guichet, en ligne (saisie par la scolarité) ou par fichier d'échange du primaire
 * (EX-SEC-16, INS-04) ; test d'entrée seulement pour un établissement privé (propriété du profil, jamais le type) ; décision
 * (acceptée, refusée avec motif) puis conversion en inscription (mêmes contrôles qu'API-01).
 * Fichier du primaire : format versionné `scholaris.public_primary.secondary_transfer.v1`, signature HMAC-SHA256 vérifiée avec la
 * clé du serveur (dérivée comme à l'émission) ; un même dossier importé deux fois est ignoré (empreinte).
 */
final class PreregistrationService
{
    public const CHANNELS = ['online', 'desk'];
    public const STATUSES = ['pending', 'accepted', 'refused', 'enrolled'];
    public const PRIMARY_FORMAT = 'scholaris.public_primary.secondary_transfer.v1';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EstablishmentProfiles $profiles,
        private readonly EnrolmentService $enrolment,
        private readonly Config $config,
        private readonly SecondaryClock $clock,
        private readonly LearnerWriter $learners,
        private readonly SecondaryDocuments $documents,
    ) {
    }

    /**
     * DOC-01 : fiche de préinscription et résultat du test d'entrée, émise par le contrat SecondaryDocuments. La fiche porte sur une
     * personne : si la préinscription n'en a pas encore, la fiche de l'élève est créée (sans inscription) et réutilisée à la
     * conversion. Corps : {academic_year_label?, confirm_different?, locale?}.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function slip(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->guard($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail('se_preregistrations', $tenantId, $establishmentId, $id, 'Préinscription introuvable.');
        $in = new Input($body);
        $year = $in->string('academic_year_label', 40, false, true);
        $confirm = $in->bool('confirm_different', false) ?? false;
        $locale = $in->enum('locale', ['fr', 'en'], false);
        $in->done();
        $personId = is_string($row['person_id']) && $row['person_id'] !== '' ? $row['person_id'] : null;
        if ($personId === null) {
            $identity = array_filter([
                'last_name' => $row['last_name'], 'first_name' => $row['first_name'], 'birth_date' => $row['birth_date'], 'gender' => $row['gender'],
            ], static fn (mixed $v): bool => is_string($v) && $v !== '');
            $guardians = [];
            if (is_string($row['guardian_name']) && trim($row['guardian_name']) !== '') {
                $guardians[] = ['kind' => 'parent', 'identity' => array_filter([
                    'last_name' => trim($row['guardian_name']), 'first_name' => 'Parent', 'phone' => is_string($row['guardian_phone']) ? $row['guardian_phone'] : null,
                ], static fn (mixed $v): bool => is_string($v) && $v !== '')];
            }
            $personId = $this->learners->createLearner($tenantId, $identity, $guardians, $principal->userId, $confirm)['person_id'];
            $this->store->update('se_preregistrations', $tenantId, $establishmentId, ['person_id' => $personId, 'updated_at' => $this->clock->now()], ['id' => $id]);
        }
        $stream = is_string($row['stream_id']) ? $this->store->find('se_streams', $tenantId, $establishmentId, $row['stream_id']) : null;
        $score = $row['entry_test_score'] === null ? '' : (string) (float) $row['entry_test_score'];
        $document = $this->documents->issue($tenantId, $establishmentId, 'preregistration', $personId, [
            'academic_year' => $year ?? '', 'requested_level' => (string) $row['target_level_code'],
            'requested_stream' => $stream === null ? '' : (string) $stream['code'], 'entry_test_line' => $score,
        ], $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.preregistration.slip', 'se_preregistration', $id, null, ['document_id' => $document->id]);

        return ['preregistration' => $this->show($principal, $establishmentId, $id), 'document' => ['id' => $document->id, 'number' => $document->number, 'status' => $document->status]];
    }

    /**
     * @param array<string, mixed> $body last_name, first_name, birth_date?, gender?, guardian_name?, guardian_phone?, target_level_code, stream_id?, channel, documents_checked?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $body): array
    {
        $this->guard($principal, $establishmentId);
        $in = new Input($body);
        $values = [
            'last_name' => (string) $in->string('last_name', 120),
            'first_name' => (string) $in->string('first_name', 120),
            'birth_date' => $in->date('birth_date', false, true),
            'gender' => $in->enum('gender', ['male', 'female'], false),
            'guardian_name' => $in->string('guardian_name', 160, false, true),
            'guardian_phone' => $in->string('guardian_phone', 20, false, true),
            'target_level_code' => (string) $in->code('target_level_code', 20),
            'stream_id' => $in->reference('stream_id', false, true),
            'channel' => (string) $in->enum('channel', self::CHANNELS),
        ];
        $documents = $in->custom('documents_checked', self::documents(...), false);
        $in->done();
        if (is_string($values['guardian_phone']) && preg_match('/^\+?[0-9 ]{8,20}$/', $values['guardian_phone']) !== 1) {
            throw HttpException::validation(['guardian_phone' => ['Numéro de téléphone invalide.']]);
        }
        $id = $this->insert($principal, $establishmentId, $values + ['documents_checked' => json_encode($documents ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

        return $this->show($principal, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $this->access->require($principal, $establishmentId, EnrolmentService::READ);

        return self::resource($this->store->findOrFail('se_preregistrations', $principal->tenantId, $establishmentId, $id, 'Préinscription introuvable.'));
    }

    /**
     * Décision ou test d'entrée : {status?: accepted|refused|pending, entry_test_score?, decision_reason?, documents_checked?}.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->guard($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail('se_preregistrations', $tenantId, $establishmentId, $id, 'Préinscription introuvable.');
        if ($row['status'] === 'enrolled') {
            throw HttpException::conflict('Préinscription déjà convertie en inscription.');
        }
        $in = new Input($body, true);
        $status = $in->enum('status', ['pending', 'accepted', 'refused'], false);
        $score = $in->number('entry_test_score', 0, 100, 0.01, false);
        $reason = $in->string('decision_reason', 255, false, true);
        $documents = $in->custom('documents_checked', self::documents(...), false);
        $in->done();
        $changes = ['updated_at' => $this->clock->now()];
        if ($score !== null) {
            if ($this->profiles->forEstablishment($tenantId, $establishmentId)->ownership === 'public') {
                throw HttpException::validation(['entry_test_score' => ['Le test d\'entrée concerne les établissements privés.']]);
            }
            $changes['entry_test_score'] = $score;
        }
        if ($status !== null) {
            if ($status === 'refused' && $reason === null) {
                throw HttpException::validation(['decision_reason' => ['Motif obligatoire pour un refus.']]);
            }
            $changes['status'] = $status;
            $changes['decided_by'] = $principal->userId;
            $changes['decision_reason'] = $reason;
        }
        if ($documents !== null) {
            $changes['documents_checked'] = json_encode($documents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $this->store->update('se_preregistrations', $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal, 'secondary.preregistration.updated', 'se_preregistration', $id, ['status' => $row['status']], $changes);

        return $this->show($principal, $establishmentId, $id);
    }

    /**
     * Conversion en inscription : {class_id, entry_date, guardians?, national_id?, age_decision_reason?, confirm_different?}.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function convert(Principal $principal, string $establishmentId, string $id, array $body): array
    {
        $this->guard($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail('se_preregistrations', $tenantId, $establishmentId, $id, 'Préinscription introuvable.');
        if ($row['status'] !== 'accepted') {
            throw HttpException::conflict('Seule une préinscription acceptée se convertit en inscription.');
        }
        $identity = array_filter([
            'last_name' => $row['last_name'], 'first_name' => $row['first_name'], 'birth_date' => $row['birth_date'], 'gender' => $row['gender'],
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');
        $plan = array_intersect_key($body, array_flip(['class_id', 'entry_date', 'guardians', 'national_id', 'age_decision_reason', 'confirm_different', 'person_id']));
        if (!isset($plan['person_id']) && is_string($row['person_id'])) {
            $plan['person_id'] = $row['person_id'];
        }
        if (!isset($plan['person_id'])) {
            $plan['identity'] = $identity;
        }
        if (!isset($plan['national_id']) && is_string($row['national_id']) && $row['national_id'] !== '') {
            $plan['national_id'] = $row['national_id'];
        }
        $plan['entry_kind'] = $row['channel'] === 'primary_file' ? 'primary_entry' : 'new';
        $plan['documents_checked'] = is_string($row['documents_checked']) ? (array) json_decode($row['documents_checked'], true) : [];
        $dossier = $this->enrolment->enrol($principal, $establishmentId, $plan);
        $record = end($dossier['records']);
        $this->store->update('se_preregistrations', $tenantId, $establishmentId, [
            'status' => 'enrolled', 'person_id' => (string) $dossier['person_id'], 'enrollment_id' => is_array($record) ? (string) $record['enrollment_id'] : null,
            'updated_at' => $this->clock->now(),
        ], ['id' => $id]);

        return $dossier;
    }

    /**
     * Fichier d'échange du primaire (EX-SEC-16) : {dossier_json, signature, target_level_code}. Rend {created, duplicate}.
     *
     * @param array<string, mixed> $body
     * @return array{preregistration: array<string, mixed>, duplicate: bool}
     */
    public function importPrimaryFile(Principal $principal, string $establishmentId, array $body): array
    {
        $this->guard($principal, $establishmentId);
        $in = new Input($body);
        $json = (string) $in->string('dossier_json', 60000);
        $signature = (string) $in->string('signature', 128);
        $level = (string) $in->code('target_level_code', 20);
        $in->done();
        $dossier = json_decode($json, true);
        if (!is_array($dossier) || ($dossier['format'] ?? null) !== self::PRIMARY_FORMAT || !is_array($dossier['pupil'] ?? null)) {
            throw HttpException::validation(['dossier_json' => ['Fichier d\'échange non reconnu : format ' . self::PRIMARY_FORMAT . ' attendu.']]);
        }
        if (!hash_equals(hash_hmac('sha256', $json, $this->key()), strtolower($signature))) {
            throw HttpException::validation(['signature' => ['Signature invalide : le dossier a été modifié ou ne vient pas de cette plateforme.']]);
        }
        $pupil = $dossier['pupil'];
        $source = 'pp:' . substr(hash('sha256', $json), 0, 40);
        $existing = $this->store->select('se_preregistrations', $principal->tenantId, $establishmentId, ['source_ref' => $source], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            return ['preregistration' => self::resource($existing), 'duplicate' => true];
        }
        $last = is_string($pupil['last_name'] ?? null) ? trim($pupil['last_name']) : '';
        $first = is_string($pupil['first_name'] ?? null) ? trim($pupil['first_name']) : '';
        if ($last === '' || $first === '') {
            throw HttpException::validation(['dossier_json' => ['Nom et prénom de l\'élève absents du dossier.']]);
        }
        $birth = is_string($pupil['birth_date'] ?? null) && EnrolmentService::isDate($pupil['birth_date']) ? $pupil['birth_date'] : null;
        $sex = $pupil['sex'] ?? null;
        $gender = in_array($sex, ['M', 'm', 'male'], true) ? 'male' : (in_array($sex, ['F', 'f', 'female'], true) ? 'female' : null);
        $summary = ['exam' => $dossier['exam'] ?? null, 'annual_mentions' => $dossier['annual_mentions'] ?? [], 'school' => $dossier['school'] ?? null, 'issued_at' => $dossier['issued_at'] ?? null];
        $id = $this->insert($principal, $establishmentId, [
            'last_name' => mb_substr($last, 0, 120), 'first_name' => mb_substr($first, 0, 120), 'birth_date' => $birth, 'gender' => $gender,
            'guardian_name' => null, 'guardian_phone' => null, 'target_level_code' => $level, 'stream_id' => null, 'channel' => 'primary_file',
            'documents_checked' => '[]', 'source_ref' => $source, 'source_payload' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return ['preregistration' => $this->show($principal, $establishmentId, $id), 'duplicate' => false];
    }

    private function guard(Principal $principal, string $establishmentId): void
    {
        $this->access->require($principal, $establishmentId, EnrolmentService::MANAGE);
        if (!$this->profiles->forEstablishment($principal->tenantId, $establishmentId)->stored) {
            throw HttpException::conflict('Ouvrez d\'abord l\'application pour cet établissement (profil à confirmer).');
        }
    }

    /** @param array<string, scalar|null> $values */
    private function insert(Principal $principal, string $establishmentId, array $values): string
    {
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_preregistrations', $principal->tenantId, $establishmentId, ['id' => $id, 'status' => 'pending'] + $values + [
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.preregistration.created', 'se_preregistration', $id, null, ['channel' => $values['channel'] ?? null]);

        return $id;
    }

    private function key(): string
    {
        $secret = (string) $this->config->get('sync.secret_key', '');
        if ($secret === '') {
            $secret = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        }
        if ($secret === '') {
            throw new HttpException(503, 'Vérification impossible', 'Aucune clé de signature sur ce serveur : le dossier du primaire ne peut pas être vérifié.');
        }

        return hash_hmac('sha256', self::PRIMARY_FORMAT, $secret);
    }

    /** @return list<string> */
    private static function documents(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new \InvalidArgumentException('Liste de 20 pièces au plus.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > 80) {
                throw new \InvalidArgumentException('Pièce : texte de 80 caractères au plus.');
            }
        }

        return array_map('trim', $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => $row['person_id'], 'last_name' => (string) $row['last_name'], 'first_name' => (string) $row['first_name'],
            'birth_date' => $row['birth_date'], 'gender' => $row['gender'], 'guardian_name' => $row['guardian_name'], 'guardian_phone' => $row['guardian_phone'],
            'target_level_code' => (string) $row['target_level_code'], 'stream_id' => $row['stream_id'], 'channel' => (string) $row['channel'],
            'entry_test_score' => $row['entry_test_score'] === null ? null : (float) $row['entry_test_score'], 'status' => (string) $row['status'],
            'documents_checked' => is_string($row['documents_checked']) ? json_decode($row['documents_checked'], true) : [],
            'source' => is_string($row['source_payload']) ? json_decode($row['source_payload'], true) : null,
            'enrollment_id' => $row['enrollment_id'], 'decision_reason' => $row['decision_reason'], 'decided_by' => $row['decided_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
