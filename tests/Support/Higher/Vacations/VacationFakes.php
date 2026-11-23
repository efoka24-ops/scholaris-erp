<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Vacations;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublures des vacations : comptes liés, personnel (grade), saisie des notes (RG-ENS-5), messagerie, documents, paramètres. */
final class VacationFakes implements UserPersonLink, StaffDirectory, GradeBook, Messenger, HigherDocumentIssuer, SettingsReader
{
    /** @var array<string, string> client:utilisateur => personne */
    public array $links = [];
    /** @var array<string, StaffInfo> */
    public array $staff = [];
    /** @var array<string, bool> EC => saisie complète */
    public array $complete = [];
    /** @var array<string, mixed> */
    public array $settings = [];
    /** @var list<array{person: string, template: string, variables: array<string, scalar>}> */
    public array $sent = [];
    /** @var list<array{code: string, person: string, data: array<string, scalar|null>}> */
    public array $issued = [];

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . ':' . $userId] ?? null;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->staff[$tenantId . ':' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return [];
    }

    public function assessmentsOf(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): array
    {
        return [];
    }

    public function allLocked(string $tenantId, string $establishmentId, string $curriculumId, ?int $semesterOrdinal = null): bool
    {
        return true;
    }

    public function marksOf(string $tenantId, string $establishmentId, string $assessmentId): array
    {
        return [];
    }

    public function elementComplete(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): bool
    {
        return $this->complete[$elementId] ?? true;
    }

    public function send(string $tenantId, string $establishmentId, string $personId, string $templateKey, array $variables = [], array $channels = [], bool $urgent = false): DeliveryResult
    {
        $this->sent[] = ['person' => $personId, 'template' => $templateKey, 'variables' => $variables];

        return new DeliveryResult(DeliveryResult::SENT, 'm' . count($this->sent));
    }

    public function issueFor(string $tenantId, string $establishmentId, string $code, string $personId, string $schoolId, ?string $programId, ?string $levelId, array $data, string $issuedBy, ?string $locale = null): array
    {
        $this->issued[] = ['code' => $code, 'person' => $personId, 'data' => $data];

        return ['id' => 'doc-' . count($this->issued), 'code' => $code, 'number' => 'ETV-EC-2026-00000' . count($this->issued)];
    }

    public function value(string $tenantId, ?string $establishmentId, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }
}
