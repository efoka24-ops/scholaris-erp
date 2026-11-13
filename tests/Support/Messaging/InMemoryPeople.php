<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/** Doublure en mémoire de l'annuaire des personnes, des garants et des consentements (module People). */
final class InMemoryPeople implements PersonDirectory, ConsentChecker
{
    /** @var array<string, PersonSummary> */
    private array $persons = [];
    /** @var array<string, list<string>> apprenant => garants */
    private array $guarantors = [];
    /** @var array<string, true> */
    private array $consents = [];

    public function add(string $tenant, string $id, string $first, string $last, ?string $phone = null, ?string $email = null, string $locale = 'fr', bool $consent = true): void
    {
        $this->persons[$tenant . '|' . $id] = new PersonSummary($id, $tenant, $first, $last, $phone, $email, $locale);
        if ($consent) {
            $this->consents[$tenant . '|' . $id] = true;
        }
    }

    public function revokeConsent(string $tenant, string $id): void
    {
        unset($this->consents[$tenant . '|' . $id]);
    }

    public function guarantor(string $tenant, string $learner, string $guarantor): void
    {
        $this->guarantors[$tenant . '|' . $learner][] = $guarantor;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->persons[$tenantId . '|' . $personId] ?? null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        $found = [];
        foreach ($personIds as $id) {
            if (isset($this->persons[$tenantId . '|' . $id])) {
                $found[] = $this->persons[$tenantId . '|' . $id];
            }
        }

        return $found;
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return $this->findMany($tenantId, $this->guarantors[$tenantId . '|' . $learnerId] ?? []);
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        $ids = [];
        foreach ($this->guarantors as $key => $guarantors) {
            if (str_starts_with($key, $tenantId . '|') && in_array($guarantorId, $guarantors, true)) {
                $ids[] = substr($key, strlen($tenantId) + 1);
            }
        }

        return $this->findMany($tenantId, $ids);
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return $purpose === ConsentChecker::COMMUNICATION && isset($this->consents[$tenantId . '|' . $personId]);
    }
}
