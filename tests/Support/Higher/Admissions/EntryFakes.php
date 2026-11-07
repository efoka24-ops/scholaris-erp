<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Admissions;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccountView;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Tests\Support\Billing\InMemoryPeople;

/**
 * Doublures des contrats consommés par Admissions et Enrolment : notifications (NOT-01 à NOT-04), documents (DOC-01 à DOC-05),
 * comptes de scolarité, blocages financiers, création de compte du candidat. Aucune dépendance au réseau.
 */
final class EntryFakes implements HigherNotifier, HigherDocumentIssuer, StudentAccounts, FinancialHolds, AccountProvisioner
{
    /** @var list<array{code: string, person: string, variables: array<string, scalar>, dedupe: ?string}> */
    public array $notifications = [];

    /** @var list<array{code: string, person: string, school: string, data: array<string, scalar|null>}> */
    public array $documents = [];

    /** @var list<StudentAccountView> */
    public array $accounts = [];

    /** @var array<string, true> */
    public array $blocked = [];

    public function __construct(private readonly InMemoryPeople $people)
    {
    }

    public function notify(string $tenantId, string $establishmentId, string $code, string $personId, array $variables = [], ?string $dedupeKey = null): array
    {
        foreach ($this->notifications as $sent) {
            if ($dedupeKey !== null && $sent['dedupe'] === $dedupeKey) {
                return [['recipient_id' => $personId, 'status' => 'duplicate', 'reason' => null]];
            }
        }
        $this->notifications[] = ['code' => $code, 'person' => $personId, 'variables' => $variables, 'dedupe' => $dedupeKey];

        return [['recipient_id' => $personId, 'status' => 'sent', 'reason' => null]];
    }

    /** @return list<string> codes envoyés à la personne */
    public function codesFor(string $personId): array
    {
        return array_values(array_map(static fn (array $n): string => $n['code'], array_filter($this->notifications, static fn (array $n): bool => $n['person'] === $personId)));
    }

    public function issueFor(string $tenantId, string $establishmentId, string $code, string $personId, string $schoolId, ?string $programId, ?string $levelId, array $data, string $issuedBy, ?string $locale = null): array
    {
        $this->documents[] = ['code' => $code, 'person' => $personId, 'school' => $schoolId, 'data' => $data];
        $n = count($this->documents);

        return ['id' => 'doc-' . $n, 'code' => $code, 'number' => sprintf('XXX-EC-2026-%06d', $n), 'verification_token' => 'tok-' . $n, 'status' => 'valid', 'data' => $data];
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return array_values(array_filter($this->accounts, static fn (StudentAccountView $a): bool => $a->personId === $personId));
    }

    public function forEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_values(array_filter($this->accounts, static fn (StudentAccountView $a): bool => $a->establishmentId === $establishmentId));
    }

    public function activeScopes(): array
    {
        return [];
    }

    public function openAccount(string $establishmentId, string $personId, string $yearId, string $programId, string $invoiceId): void
    {
        $this->accounts[] = new StudentAccountView('acc-' . (count($this->accounts) + 1), $establishmentId, $yearId, $personId, '', $programId, null, $invoiceId, null, 0, 0, 0, true, [], []);
    }

    public function isBlocked(string $tenantId, string $establishmentId, string $personId, string $scope): bool
    {
        return isset($this->blocked[$personId . '|' . $scope]);
    }

    public function balanceXaf(string $tenantId, string $establishmentId, string $personId): int
    {
        return 0;
    }

    public function provision(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale = 'fr', ?string $personId = null): array
    {
        $personId ??= 'p-new-' . strtolower($lastName);
        $this->people->add($tenantId, $personId, $firstName, $lastName, $phone);

        return ['person_id' => $personId, 'user_id' => 'u-' . $personId];
    }
}
