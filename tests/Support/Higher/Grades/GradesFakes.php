<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Grades;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\PublicationGuard;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/**
 * Doublures des contrats lus par Grades : inscriptions (Enrolment), comptes et personnes (Identity), personnel et affectations
 * (Staff), blocages financiers (Billing), notifications (Notifications), double authentification (Assessment), garde de publication.
 */
final class GradesFakes implements StudentDirectory, UserPersonLink, FinancialHolds, HigherNotifier, RecentSecondFactor, PublicationGuard
{
    /** @var array<string, EnrolmentInfo> */
    public array $enrolments = [];

    /** @var array<string, string> « client:utilisateur » => personne */
    public array $links = [];

    /** @var array<string, StaffInfo> */
    public array $staff = [];

    /** @var list<AssignmentInfo> */
    public array $assignments = [];

    /** @var array<string, true> */
    public array $blocked = [];

    /** @var array<string, true> */
    public array $recent2fa = [];

    /** @var list<array{code: string, person_id: string, variables: array<string, scalar>, dedupe: ?string}> */
    public array $notifications = [];

    public ?string $publicationRefusal = null;

    public function enrol(string $personId, string $levelId, ?string $groupId, string $year, string $matricule): void
    {
        $this->enrolments[$personId] = new EnrolmentInfo('enr-' . $personId, $personId, $matricule, $year, 'EC', 'CF', $levelId, $groupId, 'day', 'enrolled', null, 1, null);
    }

    public function link(string $tenantId, string $userId, string $personId): void
    {
        $this->links[$tenantId . ':' . $userId] = $personId;
    }

    public function teacher(string $tenantId, string $userId, string $personId, string $elementId, ?string $groupId, string $establishmentId): void
    {
        $this->link($tenantId, $userId, $personId);
        $this->staff[$tenantId . ':' . $personId] = new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, 'assistant', 'active', 'staff-' . $personId);
        $this->assignments[] = new AssignmentInfo('as-' . count($this->assignments), $establishmentId, 'staff-' . $personId, 'cm', 1200, 0, 'active', $elementId, $groupId, 'L1', null, null, null, null, null);
    }

    // StudentDirectory
    public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo
    {
        return null;
    }

    public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo
    {
        return null;
    }

    public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array
    {
        return isset($this->enrolments[$personId]) ? [$this->enrolments[$personId]] : [];
    }

    public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
    {
        foreach ($this->enrolments as $enrolment) {
            if ($enrolment->id === $enrolmentId) {
                return $enrolment;
            }
        }

        return null;
    }

    public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
    {
        return array_values(array_filter($this->enrolments, static fn (EnrolmentInfo $e): bool => $e->academicYearId === $academicYearId && ($e->levelId === $structureId || $e->groupId === $structureId)));
    }

    public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
    {
        return false;
    }

    // UserPersonLink
    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . ':' . $userId] ?? null;
    }

    // FinancialHolds
    public function isBlocked(string $tenantId, string $establishmentId, string $personId, string $scope): bool
    {
        return isset($this->blocked[$personId . ':' . $scope]);
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $personId): int
    {
        return 0;
    }

    // HigherNotifier
    public function notify(string $tenantId, string $establishmentId, string $code, string $personId, array $variables = [], ?string $dedupeKey = null): array
    {
        $this->notifications[] = ['code' => $code, 'person_id' => $personId, 'variables' => $variables, 'dedupe' => $dedupeKey];

        return [['recipient_id' => $personId, 'status' => 'sent', 'reason' => null]];
    }

    // RecentSecondFactor (Assessment)
    public function isRecent(string $tenantId, string $userId): bool
    {
        return isset($this->recent2fa[$tenantId . ':' . $userId]);
    }

    // PublicationGuard
    public function refusal(string $tenantId, string $establishmentId, array $assessment): ?string
    {
        return $this->publicationRefusal;
    }
}
