<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/**
 * Doublure en mémoire des contrats voisins de la messagerie interne : comptes (Identity), personnel (Staff), périmètre
 * des enseignants (People), direction, documents émis (Documents).
 */
final class ChatWorld implements UserPersonLink, StaffDirectory, TeachingScope, DirectionDirectory, DocumentCatalog
{
    /** @var array<string, string> tenant|user => personne */
    private array $users = [];
    /** @var array<string, string> tenant|personne => statut */
    private array $staff = [];
    /** @var array<string, list<string>> tenant|établissement => enseignants */
    private array $teachers = [];
    /** @var array<string, list<string>> tenant|enseignant => apprenants */
    private array $scope = [];
    /** @var array<string, list<string>> tenant|établissement => direction */
    private array $direction = [];
    /** @var array<string, list<IssuedDocument>> tenant|apprenant => documents */
    private array $documents = [];

    public function user(string $tenant, string $userId, string $personId): void
    {
        $this->users[$tenant . '|' . $userId] = $personId;
    }

    public function teacher(string $tenant, string $establishment, string $personId, array $learners, string $status = 'active'): void
    {
        $this->staff[$tenant . '|' . $personId] = $status;
        $this->teachers[$tenant . '|' . $establishment][] = $personId;
        $this->scope[$tenant . '|' . $personId] = $learners;
    }

    public function direction(string $tenant, string $establishment, string $personId): void
    {
        $this->direction[$tenant . '|' . $establishment][] = $personId;
    }

    public function document(string $tenant, string $learnerId, string $id, string $type = 'certificate'): void
    {
        $this->documents[$tenant . '|' . $learnerId][] = new IssuedDocument($id, 'DOC-' . $id, 'tok-' . $id, IssuedDocument::VALID, $type, $learnerId);
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->users[$tenantId . '|' . $userId] ?? null;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        $status = $this->staff[$tenantId . '|' . $personId] ?? null;

        return $status === null ? null : new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, null, $status, 'st-' . $personId);
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return $this->teachers[$tenantId . '|' . $establishmentId] ?? [];
    }

    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array
    {
        return $this->scope[$tenantId . '|' . $teacherPersonId] ?? [];
    }

    public function directionPersonIds(string $tenantId, string $establishmentId): array
    {
        return $this->direction[$tenantId . '|' . $establishmentId] ?? [];
    }

    public function validFor(string $tenantId, string $personId, int $limit = 50): array
    {
        return $this->documents[$tenantId . '|' . $personId] ?? [];
    }

}
