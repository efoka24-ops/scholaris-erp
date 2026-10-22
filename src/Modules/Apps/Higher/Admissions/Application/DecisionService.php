<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\ApplicationStatus;
use Scholaris\Modules\Apps\Higher\Admissions\Domain\Prerequisites;
use Scholaris\Modules\Apps\Higher\Admissions\Infrastructure\AdmissionsStore;
use Scholaris\Modules\Apps\Higher\Support\WriteGate;
use Scholaris\Modules\Apps\Higher\Workflows\HigherWorkflows;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowResolver;

/**
 * Décisions d'admission motivées (EX-ADM-06), prises par le directeur d'école (R04, `higher.admission.decide`) :
 *  - RG-ADM-1 / TST-02 : « admis » exige des prérequis satisfaits ET toutes les pièces bloquantes vérifiées par la scolarité ;
 *  - RG-ADM-2 : « admis sous réserve de pièce » jusqu'à une date paramétrée (défaut 30 jours), puis « refusé » + notification
 *    (tâche planifiée `higher:reserve-expiry`) ;
 *  - RG-ADM-3 : au-delà des places, seulement avec un motif de dépassement, tracé au journal et dans la décision ;
 *  - NOT-03 (SMS, e-mail) et lettre de décision DOC-03.
 */
final class DecisionService
{
    public const TABLE = 'hi_admission_decisions';

    public function __construct(
        private readonly AdmissionsStore $store,
        private readonly AdmissionsAccess $access,
        private readonly ApplicationService $applications,
        private readonly CampaignService $campaigns,
        private readonly CandidateMessages $messages,
        private readonly OrgUnitSettingsReader $settings,
        private readonly WriteGate $gate,
        private readonly AdmissionsClock $clock,
        private readonly WorkflowResolver $workflows,
        private readonly RecentSecondFactor $secondFactor,
    ) {
    }

    /** @return array<string, mixed> */
    public function decide(Principal $principal, string $establishmentId, string $id, string $decision, string $reason, ?string $overCapacityReason): array
    {
        $tenantId = $principal->tenantId;
        // Qui décide : la fonction du circuit « décision d'admission » de l'établissement (défaut : le directeur d'école,
        // `higher.admission.decide`). Prérequis, pièces vérifiées, motif et audit ci-dessous valent quel que soit le réglage.
        $step = $this->workflows->resolve($tenantId, $establishmentId, HigherWorkflows::APPLICATION, HigherWorkflows::ADMISSION)->step('decision');
        $application = $this->applications->managed($principal, $establishmentId, $id, [$step->permission()]);
        if ($step->recentConfirmation && !$this->secondFactor->isRecent($tenantId, $principal->userId)) {
            throw new HttpException(403, 'Double authentification requise', 'Ressaisissez votre code de double authentification pour cette décision.', 'https://api.scholaris.cm/errors/second-factor-required');
        }
        if (!in_array($decision, ApplicationStatus::DECISIONS, true)) {
            throw HttpException::validation(['decision' => ['Décision attendue parmi : ' . implode(', ', ApplicationStatus::DECISIONS) . '.']]);
        }
        if (!in_array($application['status'], ApplicationStatus::DECIDABLE, true)) {
            throw HttpException::conflict('Décision impossible : la candidature doit être complète (pièces déposées, frais de dossier payés) et non inscrite.');
        }
        $campaign = $this->campaigns->get($tenantId, $establishmentId, (string) $application['campaign_id']);
        if ($campaign['status'] === 'cancelled') {
            throw HttpException::conflict('Campagne annulée : aucune décision.');
        }
        $conditionalUntil = null;
        if (in_array($decision, [ApplicationStatus::ADMITTED, ApplicationStatus::CONDITIONAL], true)) {
            $this->assertPrerequisites($campaign, $application, $decision);
            if ($decision === ApplicationStatus::CONDITIONAL) {
                $conditionalUntil = $this->clock->plusDays($this->conditionalDays($tenantId, $establishmentId, (string) $application['school_id']));
            }
        }
        $overCapacity = false;
        if (in_array($decision, [ApplicationStatus::ADMITTED, ApplicationStatus::CONDITIONAL], true)
            && !in_array($application['status'], ApplicationStatus::HOLDING_SEAT, true)
            && $this->seatsTaken($tenantId, $establishmentId, (string) $campaign['id']) >= (int) $campaign['seats']) {
            if ($overCapacityReason === null) {
                throw HttpException::conflict(sprintf(
                    'Les %d places de la campagne sont prises : une admission supplémentaire exige une décision motivée du directeur d\'école (over_capacity_reason, RG-ADM-3).',
                    (int) $campaign['seats'],
                ));
            }
            $overCapacity = true;
        }

        $decisionId = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $decision, $reason, $overCapacity, $overCapacityReason, $conditionalUntil, $principal, $now, $decisionId): void {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $decisionId, 'application_id' => $id, 'decision' => $decision, 'reason' => $reason, 'over_capacity' => $overCapacity ? 1 : 0,
                'over_capacity_reason' => $overCapacity ? $overCapacityReason : null, 'conditional_until' => $conditionalUntil,
                'decision_source' => 'manual', 'decided_by' => $principal->userId, 'decided_at' => $now,
            ]);
            $this->store->update(ApplicationService::TABLE, $tenantId, $establishmentId, [
                'status' => $decision, 'decision_reason' => $reason, 'conditional_until' => $conditionalUntil,
                'over_capacity' => $overCapacity ? 1 : 0, 'decided_by' => $principal->userId, 'decided_at' => $now, 'updated_at' => $now,
            ], ['id' => $id]);
        });
        $this->access->audit($tenantId, $principal->userId, 'higher.admission.decided', 'hi_application', $id, ['status' => $application['status']], [
            'decision' => $decision, 'reason' => $reason, 'conditional_until' => $conditionalUntil,
        ]);
        if ($overCapacity) {
            $this->access->audit($tenantId, $principal->userId, 'higher.admission.over_capacity', 'hi_campaign', (string) $campaign['id'], null, [
                'application_id' => $id, 'seats' => (int) $campaign['seats'], 'reason' => $overCapacityReason,
            ]);
        }
        $this->announce($this->applications->get($tenantId, $establishmentId, $id), $decision, $reason, $conditionalUntil, $decisionId, $principal->userId);

        return $this->applications->get($tenantId, $establishmentId, $id);
    }

    /**
     * RG-ADM-2 : les « admis sous réserve » dont la date est passée deviennent « refusé », avec notification. Tous les clients dont
     * l'application est ouverte en écriture (WriteGate : un client suspendu est sauté).
     *
     * @return array{expired: int, skipped_tenants: int}
     */
    public function expireConditionals(?string $day = null): array
    {
        $today = $day ?? $this->clock->today();
        $expired = 0;
        $skipped = [];
        foreach ($this->store->scopes(ApplicationService::TABLE) as [$tenantId, $establishmentId]) {
            if (!$this->gate->allowsWrites($tenantId)) {
                $skipped[$tenantId] = true;
                continue;
            }
            $rows = $this->store->select(ApplicationService::TABLE, $tenantId, $establishmentId, ['status' => ApplicationStatus::CONDITIONAL], 'conditional_until, id', 100000, 'conditional_until < ?', [$today]);
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                $reason = sprintf('Admission sous réserve expirée le %s : pièce exigée non fournie ou non vérifiée (RG-ADM-2).', $row['conditional_until']);
                $decisionId = (string) new Ulid();
                $now = $this->clock->now();
                $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $reason, $decisionId, $now): void {
                    $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                        'id' => $decisionId, 'application_id' => $id, 'decision' => ApplicationStatus::REJECTED, 'reason' => $reason, 'over_capacity' => 0,
                        'over_capacity_reason' => null, 'conditional_until' => null, 'decision_source' => 'expiry', 'decided_by' => null, 'decided_at' => $now,
                    ]);
                    $this->store->update(ApplicationService::TABLE, $tenantId, $establishmentId, [
                        'status' => ApplicationStatus::REJECTED, 'decision_reason' => $reason, 'decided_by' => null, 'decided_at' => $now, 'updated_at' => $now,
                    ], ['id' => $id]);
                });
                $this->access->audit($tenantId, null, 'higher.admission.reserve_expired', 'hi_application', $id, ['status' => ApplicationStatus::CONDITIONAL], ['status' => ApplicationStatus::REJECTED]);
                $this->messages->notify(CandidateMessages::DECISION, $row, ApplicationStatus::label(ApplicationStatus::REJECTED), 'adm-decision-' . $decisionId);
                ++$expired;
            }
        }

        return ['expired' => $expired, 'skipped_tenants' => count($skipped)];
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $application
     */
    private function assertPrerequisites(array $campaign, array $application, string $decision): void
    {
        $decoded = json_decode((string) $campaign['prerequisites_json'], true);
        $prerequisites = Prerequisites::fromArray(is_array($decoded) ? $decoded : []);
        $unmet = $prerequisites->unmetBy(
            (string) $application['diploma'],
            isset($application['diploma_series']) ? (string) $application['diploma_series'] : null,
            isset($application['diploma_year']) ? (int) $application['diploma_year'] : null,
            isset($application['diploma_mention']) ? (string) $application['diploma_mention'] : null,
        );
        if ($unmet !== []) {
            throw HttpException::validation(['decision' => $unmet], 'Prérequis non satisfaits (EX-ADM-03) : admission impossible.');
        }
        $unverified = [];
        foreach ($application['documents'] as $document) {
            if ((int) $document['blocking'] === 1 && $document['status'] !== 'verified') {
                $unverified[] = (string) $document['label'];
            }
        }
        if ($decision === ApplicationStatus::ADMITTED && $unverified !== []) {
            throw HttpException::validation(
                ['decision' => array_map(static fn (string $label): string => 'Pièce bloquante non vérifiée : ' . $label . '.', $unverified)],
                '« Admis » impossible sans pièce vérifiée (RG-ADM-1) : vérifiez les pièces ou décidez « admis sous réserve ».',
            );
        }
        if ($decision === ApplicationStatus::CONDITIONAL && $unverified === []) {
            throw HttpException::validation(['decision' => ['Toutes les pièces bloquantes sont vérifiées : décidez « admis ».']]);
        }
    }

    private function seatsTaken(string $tenantId, string $establishmentId, string $campaignId): int
    {
        $taken = 0;
        foreach (ApplicationStatus::HOLDING_SEAT as $status) {
            $taken += $this->store->count(ApplicationService::TABLE, $tenantId, $establishmentId, ['campaign_id' => $campaignId, 'status' => $status]);
        }

        return $taken;
    }

    private function conditionalDays(string $tenantId, string $establishmentId, string $schoolId): int
    {
        $days = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, AdmissionPermissions::CONDITIONAL_DAYS, AdmissionPermissions::DEFAULT_CONDITIONAL_DAYS);

        return is_int($days) && $days > 0 ? $days : AdmissionPermissions::DEFAULT_CONDITIONAL_DAYS;
    }

    /** @param array<string, mixed> $application */
    private function announce(array $application, string $decision, string $reason, ?string $conditionalUntil, string $decisionId, string $actorId): void
    {
        $label = ApplicationStatus::label($decision);
        $letter = $this->messages->issue('DOC-03', $application, [
            'decision' => $label, 'decision_reason' => $reason, 'response_deadline' => $conditionalUntil ?? $this->clock->plusDays(30),
        ], $actorId);
        if ($letter !== null) {
            $this->store->update(ApplicationService::TABLE, (string) $application['tenant_id'], (string) $application['establishment_id'], ['letter_document_id' => $letter], ['id' => $application['id']]);
        }
        $this->messages->notify(CandidateMessages::DECISION, $application, $label, 'adm-decision-' . $decisionId);
    }
}
