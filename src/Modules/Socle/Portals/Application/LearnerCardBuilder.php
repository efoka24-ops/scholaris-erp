<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Application;

use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/** Fiche d'un apprenant pour les portails, composée uniquement à partir des contrats des autres modules. */
final class LearnerCardBuilder
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly EstablishmentDirectory $establishments,
        private readonly DebtorDirectory $debtors,
        private readonly AttendanceSummaryProvider $attendance,
        private readonly ResultsDirectory $results,
        private readonly DocumentCatalog $documents,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param bool $full faux = accès restreint (consentement absent) : identité, inscriptions et solde seulement
     * @param list<EnrollmentInfo>|null $active inscriptions actives déjà chargées
     * @return array<string, mixed>
     */
    public function build(string $tenantId, PersonSummary $person, bool $full, ?array $active = null): array
    {
        $active ??= $this->activeEnrollments($tenantId, $person->id);
        $names = [];
        foreach ($this->establishments->findMany($tenantId, array_values(array_unique(array_map(static fn (EnrollmentInfo $e): string => $e->establishmentId, $active)))) as $establishment) {
            $names[$establishment->id] = $establishment->name;
        }

        $balance = 0;
        $enrollments = [];
        $counted = [];
        foreach ($active as $enrollment) {
            // Le solde est par établissement : deux inscriptions dans le même établissement ne le comptent qu'une fois.
            if (!isset($counted[$enrollment->establishmentId])) {
                $counted[$enrollment->establishmentId] = true;
                $balance += max(0, $this->debtors->balanceXaf($tenantId, $enrollment->establishmentId, $person->id));
            }
            $enrollments[] = [
                'id' => $enrollment->id,
                'establishment_id' => $enrollment->establishmentId,
                'establishment_name' => $names[$enrollment->establishmentId] ?? null,
                'structure_id' => $enrollment->structureId,
                'status' => $enrollment->status,
            ];
        }

        $card = [
            'person' => ['id' => $person->id, 'name' => $person->fullName()],
            'access' => $full ? 'full' : 'restricted',
            'enrollments' => $enrollments,
            'balance_xaf' => $balance,
            'next_due' => ['available' => false, 'reason' => 'Aucun contrat n\'expose les échéances.'],
        ];
        if (!$full) {
            return $card + ['restricted_reason' => 'Cet apprenant majeur n\'a pas autorisé l\'accès de ses parents (consentement parent_access).'];
        }

        return $card + [
            'attendance' => $this->attendanceFor($tenantId, $person->id, $active),
            'results' => $this->publishedResults($tenantId, $person->id, $active),
            'documents' => $this->documentsFor($tenantId, $person->id, $active),
            'life_book' => ['available' => false, 'reason' => 'Aucun contrat n\'expose le cahier de vie.'],
        ];
    }

    /**
     * Inscriptions en cours : actives ou sous condition (D1 : un inscrit sous condition est scolarisé et facturé, sa
     * facture doit apparaître au portail famille).
     *
     * @return list<EnrollmentInfo>
     */
    public function activeEnrollments(string $tenantId, string $personId): array
    {
        return array_values(array_filter(
            $this->enrollments->forPerson($tenantId, $personId),
            static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && in_array($e->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true),
        ));
    }

    /**
     * @param list<EnrollmentInfo> $active
     * @return array<string, mixed>
     */
    private function attendanceFor(string $tenantId, string $personId, array $active): array
    {
        if ($active === []) {
            return ['available' => false, 'reason' => 'Aucune inscription active.'];
        }
        $now = $this->clock->now();
        $year = (int) date('Y', $now) - ((int) date('n', $now) >= 9 ? 0 : 1);
        $from = sprintf('%d-09-01', $year);
        $to = date('Y-m-d', $now);
        $sessions = $absences = $unjustified = $lates = 0;
        foreach ($active as $enrollment) {
            $summary = $this->attendance->forLearner($tenantId, $enrollment->establishmentId, $personId, $from, $to);
            $sessions += $summary->sessions;
            $absences += $summary->absences;
            $unjustified += $summary->unjustifiedAbsences;
            $lates += $summary->lates;
        }

        return ['available' => true, 'from' => $from, 'to' => $to, 'sessions' => $sessions, 'absences' => $absences, 'unjustified_absences' => $unjustified, 'lates' => $lates];
    }

    /**
     * @param list<EnrollmentInfo> $active
     * @return array<string, mixed>
     */
    private function publishedResults(string $tenantId, string $personId, array $active): array
    {
        $blocked = [];
        foreach ($active as $enrollment) {
            if ($this->debtors->isBlocked($tenantId, $enrollment->establishmentId, $personId, 'results')) {
                $blocked[$enrollment->establishmentId] = true;
            }
        }
        $items = [];
        foreach ($this->results->publishedFor($tenantId, $personId) as $result) {
            if ($result->personId !== $personId || isset($blocked[$result->establishmentId])) {
                continue;
            }
            $items[] = $this->resultRow($result);
        }

        return [
            'blocked' => $blocked !== [],
            'latest' => $items[0] ?? null,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function resultRow(PublishedResult $result): array
    {
        $row = [
            'id' => $result->id,
            'establishment_id' => $result->establishmentId,
            'period_id' => $result->periodId,
            'period_label' => $result->periodLabel,
            'average' => $result->average,
            'rank' => $result->rank,
            'class_size' => $result->classSize,
            'decision' => $result->decision,
        ];
        // Champs du supérieur (S20) : présents seulement s'ils sont renseignés (réponse du socle inchangée sinon).
        if ($result->creditsEarned !== null || $result->creditsTotal !== null) {
            $row['credits_earned'] = $result->creditsEarned;
            $row['credits_total'] = $result->creditsTotal;
        }
        if ($result->mention !== null) {
            $row['mention'] = $result->mention;
        }
        if ($result->details !== []) {
            $row['details'] = $result->details;
        }
        if ($result->session !== 'normal') {
            $row['session'] = $result->session;
        }

        return $row;
    }

    /**
     * @param list<EnrollmentInfo> $active
     * @return array<string, mixed>
     */
    private function documentsFor(string $tenantId, string $personId, array $active): array
    {
        foreach ($active as $enrollment) {
            if ($this->debtors->isBlocked($tenantId, $enrollment->establishmentId, $personId, 'documents')) {
                return ['blocked' => true, 'items' => []];
            }
        }
        $items = [];
        foreach ($this->documents->validFor($tenantId, $personId, 50) as $document) {
            $items[] = ['id' => $document->id, 'number' => $document->number, 'type' => $document->type, 'verification_token' => $document->verificationToken];
        }

        return ['blocked' => false, 'items' => $items];
    }
}
