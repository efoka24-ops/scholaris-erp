<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\EnrolmentEligibility;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Ranking;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;

/**
 * Campagnes d'admission par filière et niveau (EX-ADM-01) : création en brouillon, ouverture contrôlée par la garde IPES
 * (RG-ACA-1/2 : ni candidatures sans autorisation MINESUP du niveau ni tutelle LMD de l'année), clôture, annulation (seul cas de
 * remboursement des frais de dossier, RG-ADM-4), jury, classement (EX-ADM-04) et statistiques (EX-ADM-08).
 */
final class CampaignService
{
    public const TABLE = 'hi_campaigns';
    public const MODES = ['file', 'contest', 'mixed'];

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AdmissionsAccess $access,
        private readonly AcademicDirectory $academic,
        private readonly EnrolmentEligibility $eligibility,
        private readonly AdmissionsClock $clock,
    ) {
    }

    /**
     * @param array{level_id: string, academic_year_id: string, title: ?string, opens_on: string, closes_on: string, seats: int,
     *              application_fee_xaf: int, selection_mode: string, required_documents: ?list<array{code: string, label: string, blocking: bool, proves_prerequisite: bool}>,
     *              prerequisites: ?Prerequisites} $input
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [AdmissionPermissions::MANAGE]);
        $level = $this->academic->level($tenantId, $establishmentId, $input['level_id'])
            ?? throw HttpException::validation(['level_id' => ['Niveau introuvable dans cet établissement.']]);
        $program = $this->academic->program($tenantId, $establishmentId, $level->programId)
            ?? throw HttpException::validation(['level_id' => ['Filière du niveau introuvable.']]);
        $id = (string) new Ulid();
        $scope = ['school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $level->id];
        $this->access->assertWritable($principal, $perimeter, $scope, 'hi_campaign', $id);
        if ($program->status !== 'active') {
            throw HttpException::validation(['level_id' => ['Filière fermée : aucune campagne.']]);
        }
        if ($input['closes_on'] < $input['opens_on']) {
            throw HttpException::validation(['closes_on' => ['La clôture doit suivre l\'ouverture.']]);
        }
        $prerequisites = $input['prerequisites'] ?? Prerequisites::defaultFor($level->ordinal);
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $scope + [
            'id' => $id,
            'academic_year_id' => $input['academic_year_id'],
            'title' => $input['title'] ?? sprintf('Admission %s %s', $program->name, $level->name),
            'opens_on' => $input['opens_on'],
            'closes_on' => $input['closes_on'],
            'seats' => $input['seats'],
            'application_fee_xaf' => $input['application_fee_xaf'],
            'required_documents_json' => json_encode($input['required_documents'] ?? Prerequisites::defaultDocuments(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'prerequisites_json' => json_encode($prerequisites->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'selection_mode' => $input['selection_mode'],
            'jury_json' => null,
            'status' => 'draft',
            'created_by' => $principal->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->access->audit($tenantId, $principal->userId, 'higher.campaign.created', 'hi_campaign', $id, null, $scope + ['seats' => $input['seats']]);

        return $this->get($tenantId, $establishmentId, $id);
    }

    /**
     * Modification : titre, dates, places et jury à tout moment avant clôture ; frais, pièces, prérequis et mode en brouillon seulement.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE]);
        if (in_array($row['status'], ['closed', 'cancelled'], true)) {
            throw HttpException::conflict('Campagne close ou annulée : aucune modification.');
        }
        $draftOnly = ['application_fee_xaf', 'required_documents_json', 'prerequisites_json', 'selection_mode'];
        if ($row['status'] !== 'draft' && array_intersect(array_keys($changes), $draftOnly) !== []) {
            throw HttpException::conflict('Campagne ouverte : frais, pièces, prérequis et mode ne sont plus modifiables.');
        }
        $opens = (string) ($changes['opens_on'] ?? $row['opens_on']);
        $closes = (string) ($changes['closes_on'] ?? $row['closes_on']);
        if ($closes < $opens) {
            throw HttpException::validation(['closes_on' => ['La clôture doit suivre l\'ouverture.']]);
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.campaign.updated', 'hi_campaign', $id, null, array_diff_key($changes, ['updated_at' => 1]));

        return $this->get($principal->tenantId, $establishmentId, $id);
    }

    /**
     * Ouverture : garde IPES sur le niveau et l'année à la date du jour (RG-ACA-1, RG-ACA-2).
     *
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE]);
        if (!in_array($row['status'], ['draft', 'closed'], true)) {
            throw HttpException::conflict('Seule une campagne en brouillon ou close peut être ouverte.');
        }
        $refusal = $this->eligibility->refusalFor($principal->tenantId, $establishmentId, (string) $row['level_id'], (string) $row['academic_year_id'], $this->clock->at());
        if ($refusal !== null) {
            throw HttpException::validation(['level_id' => [$refusal]], 'Ouverture refusée : ' . $refusal);
        }

        return $this->transition($principal, $establishmentId, $row, 'open', 'higher.campaign.opened');
    }

    /** @return array<string, mixed> */
    public function close(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE]);
        if ($row['status'] !== 'open') {
            throw HttpException::conflict('Seule une campagne ouverte peut être close.');
        }

        return $this->transition($principal, $establishmentId, $row, 'closed', 'higher.campaign.closed');
    }

    /**
     * Annulation : seul cas où les frais de dossier payés deviennent remboursables (RG-ADM-4) ; les candidatures non inscrites sont
     * retirées. Le remboursement lui-même est fait par la comptabilité (indicateur `fee_refund_due`).
     *
     * @return array<string, mixed>
     */
    public function cancel(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE]);
        if ($row['status'] === 'cancelled') {
            throw HttpException::conflict('Campagne déjà annulée.');
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $reason): void {
            foreach ($this->store->select(ApplicationService::TABLE, $tenantId, $establishmentId, ['campaign_id' => $id]) as $application) {
                if ($application['status'] === ApplicationStatus::ENROLLED) {
                    continue;
                }
                $this->store->update(ApplicationService::TABLE, $tenantId, $establishmentId, [
                    'status' => ApplicationStatus::WITHDRAWN,
                    'fee_refund_due' => $application['fee_status'] === ApplicationStatus::FEE_PAID ? 1 : 0,
                    'decision_reason' => 'Campagne annulée : ' . $reason,
                    'updated_at' => $this->clock->now(),
                ], ['id' => $application['id']]);
            }
            $this->store->update(self::TABLE, $tenantId, $establishmentId, ['cancelled_reason' => $reason], ['id' => $id]);
        });

        return $this->transition($principal, $establishmentId, $row, 'cancelled', 'higher.campaign.cancelled');
    }

    /**
     * Jury d'admission (EX-ADM-04) : président et membres (personnes), lu par les décisions et les procès-verbaux.
     *
     * @param list<string> $members
     * @return array<string, mixed>
     */
    public function jury(Principal $principal, string $establishmentId, string $id, string $presidentId, array $members): array
    {
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE, AdmissionPermissions::DECIDE]);
        $jury = ['president_person_id' => $presidentId, 'member_person_ids' => array_values(array_unique(array_merge([$presidentId], $members)))];
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, [
            'jury_json' => json_encode($jury, JSON_THROW_ON_ERROR), 'updated_at' => $this->clock->now(),
        ], ['id' => $row['id']]);
        $this->access->audit($principal->tenantId, $principal->userId, 'higher.campaign.jury', 'hi_campaign', (string) $row['id'], null, $jury);

        return $this->get($principal->tenantId, $establishmentId, (string) $row['id']);
    }

    /**
     * Classement des candidatures notées (dossier, concours ou mixte) : rang avec ex æquo, liste principale et liste d'attente.
     *
     * @return list<array{application_id: string, reference: string, score_hundredths: int, rank_position: int, list_kind: string}>
     */
    public function rank(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->writable($principal, $establishmentId, $id, [AdmissionPermissions::MANAGE, AdmissionPermissions::DECIDE]);
        $candidates = [];
        $references = [];
        foreach ($this->store->select(ApplicationService::TABLE, $tenantId, $establishmentId, ['campaign_id' => $id]) as $application) {
            if ($application['score_hundredths'] === null || !in_array($application['status'], ApplicationStatus::DECIDABLE, true)) {
                continue;
            }
            $candidates[(string) $application['id']] = (int) $application['score_hundredths'];
            $references[(string) $application['id']] = (string) $application['reference'];
        }
        $ranking = Ranking::rank($candidates, (int) $row['seats']);
        $result = [];
        $this->store->transaction(function () use ($tenantId, $establishmentId, $ranking, $candidates, $references, &$result): void {
            foreach ($ranking as $applicationId => $entry) {
                $this->store->update(ApplicationService::TABLE, $tenantId, $establishmentId, [
                    'rank_position' => $entry['rank'], 'list_kind' => $entry['list'], 'updated_at' => $this->clock->now(),
                ], ['id' => $applicationId]);
                $result[] = [
                    'application_id' => $applicationId, 'reference' => $references[$applicationId], 'score_hundredths' => $candidates[$applicationId],
                    'rank_position' => $entry['rank'], 'list_kind' => $entry['list'],
                ];
            }
        });
        $this->access->audit($tenantId, $principal->userId, 'higher.campaign.ranked', 'hi_campaign', $id, null, ['ranked' => count($result)]);

        return $result;
    }

    /**
     * Statistiques de campagne (EX-ADM-08) : candidatures par état et par canal, places, admis, inscrits, frais de dossier encaissés.
     *
     * @return array<string, mixed>
     */
    public function statistics(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->readable($principal, $establishmentId, $id);
        $byStatus = array_fill_keys([
            ApplicationStatus::DRAFT, ApplicationStatus::SUBMITTED, ApplicationStatus::COMPLETE, ApplicationStatus::ADMITTED, ApplicationStatus::CONDITIONAL,
            ApplicationStatus::WAITLISTED, ApplicationStatus::REJECTED, ApplicationStatus::WITHDRAWN, ApplicationStatus::ENROLLED,
        ], 0);
        $byChannel = ['online' => 0, 'counter' => 0];
        $feesPaid = 0;
        $overCapacity = 0;
        $total = 0;
        foreach ($this->store->select(ApplicationService::TABLE, $principal->tenantId, $establishmentId, ['campaign_id' => $id], 'id', 100000) as $application) {
            ++$total;
            $byStatus[(string) $application['status']] = ($byStatus[(string) $application['status']] ?? 0) + 1;
            $byChannel[(string) $application['channel']] = ($byChannel[(string) $application['channel']] ?? 0) + 1;
            if ($application['fee_status'] === ApplicationStatus::FEE_PAID) {
                $feesPaid += (int) $row['application_fee_xaf'];
            }
            $overCapacity += (int) $application['over_capacity'];
        }
        $holding = $byStatus[ApplicationStatus::ADMITTED] + $byStatus[ApplicationStatus::CONDITIONAL] + $byStatus[ApplicationStatus::ENROLLED];

        return [
            'campaign_id' => $id,
            'seats' => (int) $row['seats'],
            'applications' => $total,
            'by_status' => $byStatus,
            'by_channel' => $byChannel,
            'seats_taken' => $holding,
            'seats_left' => max(0, (int) $row['seats'] - $holding),
            'over_capacity_admissions' => $overCapacity,
            'fees_collected_xaf' => $feesPaid,
            'enrolment_rate_percent' => $holding === 0 ? 0 : intdiv(100 * $byStatus[ApplicationStatus::ENROLLED], $holding),
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Campagne introuvable.');
    }

    /** @return array<string, mixed> */
    public function readable(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, AdmissionPermissions::READ);
        $row = $this->get($principal->tenantId, $establishmentId, $id);
        $this->access->assertReadable($perimeter, $row);

        return $row;
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function writable(Principal $principal, string $establishmentId, string $id, array $permissions): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $row = $this->get($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_campaign', $id);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function transition(Principal $principal, string $establishmentId, array $row, string $status, string $action): array
    {
        $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, ['status' => $status, 'updated_at' => $this->clock->now()], ['id' => $row['id']]);
        $this->access->audit($principal->tenantId, $principal->userId, $action, 'hi_campaign', (string) $row['id'], ['status' => $row['status']], ['status' => $status]);

        return $this->get($principal->tenantId, $establishmentId, (string) $row['id']);
    }
}
