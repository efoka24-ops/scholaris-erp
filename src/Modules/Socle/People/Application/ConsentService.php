<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Consentements horodatés et révocables ; une ligne n'est jamais supprimée, une nouvelle décision ajoute une ligne. */
final class ConsentService implements ConsentRecorder
{
    public const PURPOSES = [ConsentChecker::COMMUNICATION, ConsentChecker::PHOTO, ConsentChecker::PARENT_ACCESS];

    public function __construct(
        private readonly PdoStore $store,
        private readonly PeopleClock $clock,
        private readonly ConsentChecker $checker,
    ) {
    }

    /** @return array<string, mixed> */
    public function grant(string $tenantId, string $personId, string $purpose, string $actorId, ?string $evidenceRef = null): array
    {
        if ($this->store->find('people', $tenantId, $personId) === null) {
            throw HttpException::validation(['person_id' => ['Personne introuvable.']]);
        }
        if ($this->checker->hasConsent($tenantId, $personId, $purpose)) {
            throw HttpException::conflict('Ce consentement est déjà donné.');
        }
        $id = (string) new Ulid();
        $this->store->insert('consents', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'purpose' => $purpose,
            'granted_at' => $this->clock->now(), 'granted_by' => $actorId, 'revoked_at' => null, 'revoked_by' => null,
            'evidence_ref' => $evidenceRef,
        ]);

        return $this->get($tenantId, $id);
    }

    /** @return array<string, mixed> */
    public function revoke(string $tenantId, string $consentId, ?string $actorId): array
    {
        $row = $this->get($tenantId, $consentId);
        if ($row['revoked_at'] !== null) {
            throw HttpException::conflict('Ce consentement est déjà révoqué.');
        }
        $this->store->update('consents', $tenantId, $consentId, ['revoked_at' => $this->clock->now(), 'revoked_by' => $actorId]);

        return $this->get($tenantId, $consentId);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $consentId): array
    {
        return $this->store->find('consents', $tenantId, $consentId) ?? throw HttpException::notFound('Consentement introuvable.');
    }

    public function hasWrittenConsent(string $tenantId, string $personId, string $purpose): bool
    {
        $row = $this->store->rows(
            'SELECT evidence_ref, revoked_at FROM consents WHERE tenant_id = ? AND person_id = ? AND purpose = ? ORDER BY granted_at DESC, id DESC LIMIT 1',
            [$tenantId, $personId, $purpose],
        )[0] ?? null;

        return $row !== null && $row['revoked_at'] === null && $row['evidence_ref'] !== null;
    }

    /** @return list<array<string, mixed>> */
    public function forPerson(string $tenantId, string $personId, ?string $purpose = null): array
    {
        return $this->store->where('consents', $tenantId, ['person_id' => $personId] + ($purpose !== null ? ['purpose' => $purpose] : []));
    }
}
