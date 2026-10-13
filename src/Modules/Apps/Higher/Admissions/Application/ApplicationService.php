<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Candidatures (EX-ADM-02, EX-ADM-03) : dépôt en ligne par le candidat (R12, sa seule candidature) ou au guichet par la scolarité
 * (R07, fiche et compte créés à la volée, decisions.md R17), pièces numérisées (référence d'un document du socle) vérifiées par la
 * scolarité, frais de dossier facturés et payés par mobile money ou au guichet (contrats D4 du socle), candidature complète
 * (NOT-01, DOC-01). Les frais de dossier ne sont jamais remboursés, sauf annulation de la campagne (RG-ADM-4).
 */
final class ApplicationService
{
    public const TABLE = 'hi_applications';
    public const DOCUMENTS = 'hi_application_documents';
    public const CHANNELS = ['orange_money', 'mtn_momo', 'cash', 'transfer', 'card'];

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AdmissionsAccess $access,
        private readonly CampaignService $campaigns,
        private readonly AccountProvisioner $accounts,
        private readonly UserPersonLink $links,
        private readonly InvoiceIssuer $invoices,
        private readonly PaymentRequester $payments,
        private readonly CandidateMessages $messages,
        private readonly AdmissionsClock $clock,
    ) {
    }

    /**
     * @param array{campaign_id: string, person_id: ?string, identity: ?array{first_name: string, last_name: string, phone: ?string, email: ?string, locale: string},
     *              application_kind: string, origin_institution: ?string, diploma: string, diploma_series: ?string, diploma_year: ?int, diploma_mention: ?string} $input
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $campaign = $this->campaigns->get($tenantId, $establishmentId, $input['campaign_id']);
        $id = (string) new Ulid();
        $counter = $this->access->perimeter($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        if (!$counter->isEmpty() && $this->covers($counter, $campaign)) {
            $channel = 'counter';
            $personId = $input['person_id'];
            if ($personId === null) {
                $identity = $input['identity'] ?? throw HttpException::validation(['person_id' => ['Fiche du candidat (person_id) ou identité (identity) attendue.']]);
                $personId = $this->accounts->provision($tenantId, $identity['first_name'], $identity['last_name'], $identity['phone'], $identity['email'], $identity['locale'])['person_id'];
            }
        } else {
            $this->access->require($principal, $establishmentId, [AdmissionPermissions::SUBMIT]);
            $channel = 'online';
            $personId = $this->links->personIdForUser($tenantId, $principal->userId)
                ?? throw HttpException::forbidden('Votre compte n\'est rattaché à aucune fiche : la candidature en ligne est impossible.');
            if ($input['person_id'] !== null && $input['person_id'] !== $personId) {
                throw HttpException::forbidden('Un candidat ne dépose que sa propre candidature.');
            }
        }
        if ($campaign['status'] !== 'open') {
            throw HttpException::conflict('Cette campagne n\'est pas ouverte aux candidatures.');
        }
        $today = $this->clock->today();
        if ($today < $campaign['opens_on'] || $today > $campaign['closes_on']) {
            throw HttpException::conflict(sprintf('Candidatures reçues du %s au %s seulement.', $campaign['opens_on'], $campaign['closes_on']));
        }
        if ($input['application_kind'] === 'transfer' && $input['origin_institution'] === null) {
            throw HttpException::validation(['origin_institution' => ['Établissement d\'origine obligatoire pour un transfert.']]);
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $campaign, $id, $personId, $channel, $input, $principal, $now): void {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $id,
                'campaign_id' => $campaign['id'],
                'school_id' => $campaign['school_id'],
                'department_id' => $campaign['department_id'],
                'program_id' => $campaign['program_id'],
                'level_id' => $campaign['level_id'],
                'academic_year_id' => $campaign['academic_year_id'],
                'person_id' => $personId,
                'reference' => 'CAND-' . substr($this->clock->today(), 0, 4) . '-' . substr($id, 16),
                'channel' => $channel,
                'application_kind' => $input['application_kind'],
                'origin_institution' => $input['origin_institution'],
                'diploma' => $input['diploma'],
                'diploma_series' => $input['diploma_series'],
                'diploma_year' => $input['diploma_year'],
                'diploma_mention' => $input['diploma_mention'],
                'status' => ApplicationStatus::DRAFT,
                'fee_status' => ApplicationStatus::FEE_UNPAID,
                'fee_refund_due' => 0,
                'over_capacity' => 0,
                'created_by' => $principal->userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach (self::requirements($campaign) as $requirement) {
                $this->store->insert(self::DOCUMENTS, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(),
                    'application_id' => $id,
                    'requirement_code' => $requirement['code'],
                    'label' => $requirement['label'],
                    'blocking' => $requirement['blocking'] ? 1 : 0,
                    'proves_prerequisite' => $requirement['proves_prerequisite'] ? 1 : 0,
                    'document_ref' => null,
                    'status' => 'missing',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
        $this->access->audit($tenantId, $principal->userId, 'higher.application.created', 'hi_application', $id, null, ['campaign_id' => $campaign['id'], 'channel' => $channel]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Dépôt (ou remplacement) d'une pièce numérisée : la vérification précédente est perdue.
     *
     * @return array<string, mixed>
     */
    public function attachDocument(Principal $principal, string $establishmentId, string $id, string $code, string $documentRef): array
    {
        $application = $this->ownedOrManaged($principal, $establishmentId, $id);
        if (!in_array($application['status'], [ApplicationStatus::DRAFT, ApplicationStatus::SUBMITTED, ApplicationStatus::COMPLETE, ApplicationStatus::CONDITIONAL, ApplicationStatus::WAITLISTED], true)) {
            throw HttpException::conflict('Candidature close : aucune pièce ne peut être déposée.');
        }
        $document = $this->document($principal->tenantId, $establishmentId, $id, ['requirement_code' => $code]);
        $this->store->update(self::DOCUMENTS, $principal->tenantId, $establishmentId, [
            'document_ref' => $documentRef, 'status' => 'uploaded', 'rejection_reason' => null, 'verified_by' => null, 'verified_at' => null,
            'updated_at' => $this->clock->now(),
        ], ['id' => $document['id']]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.application.document_uploaded', 'hi_application', $id, null, ['requirement_code' => $code]);
        $this->refreshCompleteness($principal->tenantId, $establishmentId, $id, $principal->userId);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Vérification d'une pièce par la scolarité (RG-ADM-1 : seule une pièce vérifiée justifie un prérequis bloquant).
     *
     * @return array<string, mixed>
     */
    public function verifyDocument(Principal $principal, string $establishmentId, string $id, string $documentId): array
    {
        $this->managed($principal, $establishmentId, $id);
        $document = $this->document($principal->tenantId, $establishmentId, $id, ['id' => $documentId]);
        if ($document['status'] !== 'uploaded') {
            throw HttpException::conflict('Seule une pièce déposée et non encore vérifiée peut être vérifiée.');
        }
        $this->store->update(self::DOCUMENTS, $principal->tenantId, $establishmentId, [
            'status' => 'verified', 'verified_by' => $principal->userId, 'verified_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
        ], ['id' => $documentId]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.application.document_verified', 'hi_application', $id, null, ['requirement_code' => $document['requirement_code']]);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Pièce refusée : motif, le candidat est prévenu (NOT-02), la candidature redevient incomplète.
     *
     * @return array<string, mixed>
     */
    public function rejectDocument(Principal $principal, string $establishmentId, string $id, string $documentId, string $reason): array
    {
        $application = $this->managed($principal, $establishmentId, $id);
        $document = $this->document($principal->tenantId, $establishmentId, $id, ['id' => $documentId]);
        if (!in_array($document['status'], ['uploaded', 'verified'], true)) {
            throw HttpException::conflict('Aucune pièce déposée à refuser.');
        }
        $this->store->update(self::DOCUMENTS, $principal->tenantId, $establishmentId, [
            'status' => 'rejected', 'rejection_reason' => $reason, 'verified_by' => $principal->userId, 'verified_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ], ['id' => $documentId]);
        if ($application['status'] === ApplicationStatus::COMPLETE) {
            $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['status' => ApplicationStatus::SUBMITTED, 'updated_at' => $this->clock->now()], ['id' => $id]);
        }
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.application.document_rejected', 'hi_application', $id, null, ['requirement_code' => $document['requirement_code'], 'reason' => $reason]);
        $this->messages->notify(CandidateMessages::DOCUMENT_REJECTED, $application, (string) $document['label'], 'adm-doc-' . $documentId . '-' . $this->clock->now());

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Dépôt de la candidature : facture des frais de dossier au nom du candidat (sans inscription, D4/S13), puis contrôle de complétude.
     *
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $application = $this->ownedOrManaged($principal, $establishmentId, $id);
        if ($application['status'] !== ApplicationStatus::DRAFT) {
            throw HttpException::conflict('Candidature déjà déposée.');
        }
        $campaign = $this->campaigns->get($tenantId, $establishmentId, (string) $application['campaign_id']);
        if ($campaign['status'] !== 'open' || $this->clock->today() > $campaign['closes_on']) {
            throw HttpException::conflict('La campagne est close : dépôt impossible.');
        }
        $fee = (int) $campaign['application_fee_xaf'];
        $changes = ['status' => ApplicationStatus::SUBMITTED, 'submitted_at' => $this->clock->now(), 'updated_at' => $this->clock->now()];
        if ($fee > 0) {
            $invoice = $this->invoices->forCharges(new ChargeInvoice(
                $tenantId,
                $establishmentId,
                $principal->userId,
                (string) $application['person_id'],
                (string) $application['academic_year_id'],
                'hi-adm-' . $id,
                [new ChargeLine(AdmissionPermissions::DOSSIER_FEE_CODE, $this->clock->today(), $fee, 'Frais de dossier, ' . $campaign['title'])],
                null,
                (string) $application['level_id'],
            ));
            $changes['fee_invoice_id'] = $invoice->id;
            $changes['fee_status'] = $invoice->balanceXaf === 0 ? ApplicationStatus::FEE_PAID : ApplicationStatus::FEE_UNPAID;
        } else {
            $changes['fee_status'] = ApplicationStatus::FEE_NONE;
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.application.submitted', 'hi_application', $id, null, ['fee_xaf' => $fee]);
        $this->refreshCompleteness($tenantId, $establishmentId, $id, $principal->userId);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Paiement des frais de dossier : mobile money (en attente de l'opérateur, puis confirmé) ou guichet. Rappelé sans nouveau paiement,
     * il relit l'état du paiement en attente. Les frais payés ne sont pas remboursables (RG-ADM-4).
     *
     * @return array<string, mixed>
     */
    public function payFee(Principal $principal, string $establishmentId, string $id, ?string $channel, ?string $payerPhone, ?string $externalReference): array
    {
        $tenantId = $principal->tenantId;
        $application = $this->ownedOrManaged($principal, $establishmentId, $id);
        if ($application['fee_invoice_id'] === null || $application['fee_status'] === ApplicationStatus::FEE_NONE) {
            throw HttpException::conflict('Aucun frais de dossier à payer : déposez d\'abord la candidature.');
        }
        if ($application['fee_status'] === ApplicationStatus::FEE_PAID) {
            throw HttpException::conflict('Frais de dossier déjà payés.');
        }
        $outcome = null;
        if ($application['fee_status'] === ApplicationStatus::FEE_PENDING && $application['fee_payment_id'] !== null) {
            $outcome = $this->payments->status($tenantId, $establishmentId, (string) $application['fee_payment_id']);
        }
        if ($outcome === null || ($outcome->status !== PaymentOutcome::CONFIRMED && $outcome->status !== PaymentOutcome::PENDING)) {
            if ($channel === null) {
                throw HttpException::validation(['channel' => ['Canal de paiement obligatoire.']]);
            }
            if ($payerPhone === null && in_array($channel, ['orange_money', 'mtn_momo'], true)) {
                throw HttpException::validation(['payer_phone' => ['Numéro de téléphone du payeur obligatoire pour le mobile money.']]);
            }
            $invoice = $this->invoices->find($tenantId, $establishmentId, (string) $application['fee_invoice_id'])
                ?? throw HttpException::notFound('Facture des frais de dossier introuvable.');
            $outcome = $this->payments->request(new PaymentOrder(
                $tenantId,
                $establishmentId,
                $principal->userId,
                $invoice->id,
                $invoice->balanceXaf,
                $channel,
                'hi-adm-' . $id . '-' . $channel . '-' . ($externalReference ?? substr((string) new Ulid(), 10)),
                $payerPhone,
                $externalReference,
            ));
        }
        $status = $outcome->status === PaymentOutcome::CONFIRMED ? ApplicationStatus::FEE_PAID : ApplicationStatus::FEE_PENDING;
        $this->store->update(self::TABLE, $tenantId, $establishmentId, [
            'fee_payment_id' => $outcome->paymentId, 'fee_status' => $status, 'fee_receipt_number' => $outcome->receiptNumber, 'updated_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.application.fee_payment', 'hi_application', $id, null, [
            'payment_id' => $outcome->paymentId, 'status' => $outcome->status, 'amount_xaf' => $outcome->amountXaf, 'channel' => $outcome->channel,
        ]);
        $this->refreshCompleteness($tenantId, $establishmentId, $id, $principal->userId);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Note de dossier ou de concours en centièmes de point sur 20 (EX-ADM-04/05).
     *
     * @return array<string, mixed>
     */
    public function score(Principal $principal, string $establishmentId, string $id, int $scoreHundredths): array
    {
        $application = $this->managed($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE, AdmissionPermissions::DECIDE]);
        if (!in_array($application['status'], ApplicationStatus::DECIDABLE, true)) {
            throw HttpException::conflict('Seule une candidature complète peut être notée.');
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['score_hundredths' => $scoreHundredths, 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.application.scored', 'hi_application', $id, ['score_hundredths' => $application['score_hundredths']], ['score_hundredths' => $scoreHundredths]);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Retrait par le candidat ou la scolarité : les frais de dossier restent acquis (RG-ADM-4).
     *
     * @return array<string, mixed>
     */
    public function withdraw(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $application = $this->ownedOrManaged($principal, $establishmentId, $id);
        if (in_array($application['status'], [ApplicationStatus::ENROLLED, ApplicationStatus::WITHDRAWN], true)) {
            throw HttpException::conflict('Candidature déjà inscrite ou retirée.');
        }
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, [
            'status' => ApplicationStatus::WITHDRAWN, 'decision_reason' => $reason, 'fee_refund_due' => 0, 'updated_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.application.withdrawn', 'hi_application', $id, ['status' => $application['status']], ['reason' => $reason, 'fee_refundable' => false]);

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Candidature complète (frais payés ou nuls, toutes les pièces déposées) : NOT-01 et accusé de réception DOC-01, une seule fois.
     */
    public function refreshCompleteness(string $tenantId, string $establishmentId, string $id, string $actorId): void
    {
        $application = $this->get($tenantId, $establishmentId, $id);
        if ($application['status'] !== ApplicationStatus::SUBMITTED || !in_array($application['fee_status'], [ApplicationStatus::FEE_PAID, ApplicationStatus::FEE_NONE], true)) {
            return;
        }
        foreach ($application['documents'] as $document) {
            if (!in_array($document['status'], ['uploaded', 'verified'], true)) {
                return;
            }
        }
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => ApplicationStatus::COMPLETE, 'completed_at' => $this->clock->now(), 'updated_at' => $this->clock->now()], ['id' => $id]);
        if ($application['acknowledgement_document_id'] === null) {
            $documentId = $this->messages->issue('DOC-01', $application, [
                'application_ref' => (string) $application['reference'], 'submitted_on' => substr((string) ($application['submitted_at'] ?? $this->clock->now()), 0, 10),
            ], $actorId);
            if ($documentId !== null) {
                $this->store->update(self::TABLE, $tenantId, $establishmentId, ['acknowledgement_document_id' => $documentId], ['id' => $id]);
            }
            $this->messages->notify(CandidateMessages::RECEIVED, $application, 'complète', 'adm-received-' . $id);
        }
    }

    /**
     * Candidature lisible : périmètre de lecture, ou candidat propriétaire (R12, sa candidature).
     *
     * @return array<string, mixed>
     */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $application = $this->get($principal->tenantId, $establishmentId, $id);
        $perimeter = $this->access->perimeter($principal, $establishmentId, AdmissionPermissions::READ);
        if (!$perimeter->isEmpty() && $this->covers($perimeter, $application)) {
            return $application;
        }
        if ($this->isOwner($principal, $establishmentId, $application)) {
            return $application;
        }

        throw HttpException::notFound('Candidature introuvable.');
    }

    /**
     * Candidature avec ses pièces, ses décisions et sa convocation au concours.
     *
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        $application = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Candidature introuvable.');
        $application['documents'] = $this->store->select(self::DOCUMENTS, $tenantId, $establishmentId, ['application_id' => $id], 'requirement_code');
        $application['decisions'] = $this->store->select(DecisionService::TABLE, $tenantId, $establishmentId, ['application_id' => $id], 'decided_at, id');
        $application['equivalences'] = $this->store->select(ContestService::EQUIVALENCES, $tenantId, $establishmentId, ['application_id' => $id], 'origin_unit_code');

        return $application;
    }

    /**
     * Candidatures du périmètre, ou les siennes pour un candidat sans permission de lecture.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function index(Principal $principal, string $establishmentId, array $filters, int $limit, int $offset, ?string $school): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, AdmissionPermissions::READ, $school);
        if ($perimeter->isEmpty()) {
            $this->access->require($principal, $establishmentId, [AdmissionPermissions::SUBMIT]);
            $personId = $this->links->personIdForUser($principal->tenantId, $principal->userId);
            if ($personId === null) {
                return [];
            }
            $filters['person_id'] = $personId;

            return $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, $filters, 'created_at DESC, id', $limit, '1 = 1', [], $offset);
        }
        [$sql, $params] = $this->access->sqlFilter($perimeter);

        return $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, $filters, 'created_at DESC, id', $limit, $sql, $params, $offset);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function managed(Principal $principal, string $establishmentId, string $id, array $permissions = [AdmissionPermissions::MANAGE]): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $application = $this->get($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $application, 'hi_application', $id);

        return $application;
    }

    /** @return array<string, mixed> */
    private function ownedOrManaged(Principal $principal, string $establishmentId, string $id): array
    {
        $application = $this->get($principal->tenantId, $establishmentId, $id);
        $perimeter = $this->access->perimeter($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        if (!$perimeter->isEmpty() && $this->covers($perimeter, $application)) {
            return $application;
        }
        if ($this->access->has($principal, $establishmentId, [AdmissionPermissions::SUBMIT]) && $this->isOwner($principal, $establishmentId, $application)) {
            return $application;
        }
        if (!$perimeter->isEmpty()) {
            $this->access->assertWritable($principal, $perimeter, $application, 'hi_application', $id);
        }

        throw HttpException::notFound('Candidature introuvable.');
    }

    /** @param array<string, mixed> $application */
    private function isOwner(Principal $principal, string $establishmentId, array $application): bool
    {
        if (!$this->access->has($principal, $establishmentId, [AdmissionPermissions::SUBMIT])) {
            return false;
        }

        return $this->links->personIdForUser($principal->tenantId, $principal->userId) === $application['person_id'];
    }

    /** @param array<string, mixed> $row */
    private function covers(Perimeter $perimeter, array $row): bool
    {
        try {
            $this->access->assertReadable($perimeter, $row);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    /**
     * @param array<string, scalar|null> $where
     * @return array<string, mixed>
     */
    private function document(string $tenantId, string $establishmentId, string $applicationId, array $where): array
    {
        return $this->store->select(self::DOCUMENTS, $tenantId, $establishmentId, ['application_id' => $applicationId] + $where, 'id', 1)[0]
            ?? throw HttpException::notFound('Pièce introuvable pour cette candidature.');
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<array{code: string, label: string, blocking: bool, proves_prerequisite: bool}>
     */
    private static function requirements(array $campaign): array
    {
        $decoded = json_decode((string) $campaign['required_documents_json'], true);

        return Prerequisites::normalizeDocuments(is_array($decoded) ? array_values($decoded) : [])[0];
    }
}
