<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;

/**
 * Concours (EX-ADM-05, Should) : épreuves par centre et salle, convocations avec numéro de table (DOC-02) ; les notes et le
 * classement passent par ApplicationService::score et CampaignService::rank. Transferts (EX-ADM-07, Should) : équivalences d'UE
 * décidées par la commission, reportées comme UE capitalisées à l'inscription.
 */
final class ContestService
{
    public const SESSIONS = 'hi_contest_sessions';
    public const SEATS = 'hi_contest_seats';
    public const EQUIVALENCES = 'hi_transfer_equivalences';

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AdmissionsAccess $access,
        private readonly CampaignService $campaigns,
        private readonly ApplicationService $applications,
        private readonly CandidateMessages $messages,
        private readonly AdmissionsClock $clock,
    ) {
    }

    /**
     * @param array{contest_date: string, center_name: string, room_name: string, room_capacity: int} $input
     * @return array<string, mixed>
     */
    public function createSession(Principal $principal, string $establishmentId, string $campaignId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        $campaign = $this->campaigns->get($principal->tenantId, $establishmentId, $campaignId);
        $id = (string) new Ulid();
        $this->access->assertWritable($principal, $perimeter, $campaign, 'hi_campaign', $campaignId);
        if ($campaign['selection_mode'] === 'file') {
            throw HttpException::conflict('Campagne sur dossier : aucun concours.');
        }
        $this->store->insert(self::SESSIONS, $principal->tenantId, $establishmentId, $input + [
            'id' => $id, 'campaign_id' => $campaignId, 'school_id' => $campaign['school_id'], 'program_id' => $campaign['program_id'],
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.contest.session_created', 'hi_campaign', $campaignId, null, $input);

        return $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id);
    }

    /**
     * Convocations : chaque candidature complète de la campagne non encore placée reçoit une table dans la salle (dans la limite de
     * sa capacité) et sa convocation DOC-02.
     *
     * @return list<array<string, mixed>>
     */
    public function convoke(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        $session = $this->store->findOrFail(self::SESSIONS, $tenantId, $establishmentId, $sessionId, 'Épreuve introuvable.');
        $campaign = $this->campaigns->get($tenantId, $establishmentId, (string) $session['campaign_id']);
        $this->access->assertWritable($principal, $perimeter, $campaign, 'hi_contest_session', $sessionId);
        $placed = [];
        foreach ($this->store->select(self::SESSIONS, $tenantId, $establishmentId, ['campaign_id' => $campaign['id']]) as $other) {
            foreach ($this->store->select(self::SEATS, $tenantId, $establishmentId, ['session_id' => $other['id']], 'id', 100000) as $seat) {
                $placed[(string) $seat['application_id']] = true;
            }
        }
        $used = $this->store->count(self::SEATS, $tenantId, $establishmentId, ['session_id' => $sessionId]);
        $created = [];
        foreach ($this->store->select(ApplicationService::TABLE, $tenantId, $establishmentId, ['campaign_id' => $campaign['id'], 'status' => ApplicationStatus::COMPLETE], 'reference', 100000) as $application) {
            if (isset($placed[(string) $application['id']]) || $used >= (int) $session['room_capacity']) {
                continue;
            }
            ++$used;
            $seatNumber = sprintf('%s-%03d', strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $session['room_name']) ?: 'S', 0, 6)), $used);
            $documentId = $this->messages->issue('DOC-02', $application, [
                'contest_name' => (string) $campaign['title'], 'contest_date' => (string) $session['contest_date'],
                'contest_center' => $session['center_name'] . ', salle ' . $session['room_name'], 'seat_number' => $seatNumber,
            ], $principal->userId);
            $seat = [
                'id' => (string) new Ulid(), 'session_id' => $sessionId, 'application_id' => $application['id'], 'seat_number' => $seatNumber,
                'convocation_document_id' => $documentId, 'created_at' => $this->clock->now(),
            ];
            $this->store->insert(self::SEATS, $tenantId, $establishmentId, $seat);
            $created[] = $seat + ['reference' => $application['reference']];
        }
        $this->access->audit($tenantId, $principal->userId, 'higher.contest.convoked', 'hi_contest_session', $sessionId, null, ['convocations' => count($created)]);

        return $created;
    }

    /**
     * Équivalences d'UE décidées par la commission pour un transfert (EX-ADM-07) ; remplace les décisions précédentes.
     *
     * @param list<array{origin_unit_code: string, origin_unit_title: string, origin_credits: int, origin_grade_hundredths: ?int, unit_code: string, granted: bool}> $items
     * @return array<string, mixed>
     */
    public function equivalences(Principal $principal, string $establishmentId, string $applicationId, string $commissionReference, array $items): array
    {
        $tenantId = $principal->tenantId;
        $application = $this->applications->managed($principal, $establishmentId, $applicationId, [AdmissionPermissions::DECIDE, AdmissionPermissions::MANAGE]);
        if ($application['application_kind'] !== 'transfer') {
            throw HttpException::conflict('Les équivalences ne concernent qu\'une candidature de transfert.');
        }
        if ($application['status'] === ApplicationStatus::ENROLLED) {
            throw HttpException::conflict('Candidature déjà inscrite : équivalences figées.');
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $applicationId, $commissionReference, $items, $principal): void {
            $this->store->delete(self::EQUIVALENCES, $tenantId, $establishmentId, ['application_id' => $applicationId]);
            foreach ($items as $item) {
                $this->store->insert(self::EQUIVALENCES, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'application_id' => $applicationId, 'origin_unit_code' => $item['origin_unit_code'],
                    'origin_unit_title' => $item['origin_unit_title'], 'origin_credits' => $item['origin_credits'],
                    'origin_grade_hundredths' => $item['origin_grade_hundredths'], 'unit_code' => $item['unit_code'], 'granted' => $item['granted'] ? 1 : 0,
                    'commission_reference' => $commissionReference, 'decided_by' => $principal->userId, 'decided_at' => $this->clock->now(),
                ]);
            }
        });
        $this->access->audit($tenantId, $principal->userId, 'higher.transfer.equivalences', 'hi_application', $applicationId, null, [
            'commission_reference' => $commissionReference, 'granted' => count(array_filter($items, static fn (array $i): bool => $i['granted'])),
        ]);

        return $this->applications->get($tenantId, $establishmentId, $applicationId);
    }
}
