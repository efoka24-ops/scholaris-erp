<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\People\Domain\Majority;
use Scholaris\Modules\Socle\People\Domain\RelationKind;
use Scholaris\Modules\Socle\People\Infrastructure\PdoActorDirectory;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/**
 * Qui voit quoi. people.manage et people.read donnent la vue d'ensemble du client ; sans elles, une personne ne voit que
 * sa propre fiche, ses apprenants (parent, tuteur, garant) et, pour un enseignant, les apprenants de ses classes.
 * Un apprenant majeur ne partage ses données avec ses parents que s'il a donné le consentement « parent_access ».
 */
final class AccessGuard
{
    public const READ = 'people.read';
    public const MANAGE = 'people.manage';
    public const HEALTH = 'people.health.read';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PdoStore $store,
        private readonly PdoActorDirectory $actors,
        private readonly TeachingScope $teaching,
        private readonly ConsentChecker $consents,
        private readonly PeopleClock $clock,
    ) {
    }

    public function can(Principal $principal, string $permission, ?string $establishmentId): bool
    {
        return $this->permissions->can($principal, $permission, $establishmentId);
    }

    public function requireManage(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->can($principal, self::MANAGE, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de modifier les dossiers des personnes.');
        }
    }

    public function requireHealth(Principal $principal, ?string $establishmentId): void
    {
        if (!$this->can($principal, self::HEALTH, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les données de santé.');
        }
    }

    public function hasFullRead(Principal $principal, ?string $establishmentId): bool
    {
        return $this->can($principal, self::READ, $establishmentId) || $this->can($principal, self::MANAGE, $establishmentId);
    }

    public function requireView(Principal $principal, ?string $establishmentId, string $personId): void
    {
        if (!$this->canView($principal, $establishmentId, $personId)) {
            throw HttpException::notFound('Personne introuvable.');
        }
    }

    public function canView(Principal $principal, ?string $establishmentId, string $personId): bool
    {
        if ($this->hasFullRead($principal, $establishmentId)) {
            return true;
        }

        return in_array($personId, $this->visibleIds($principal), true);
    }

    /** @return list<string> personnes visibles par un appelant sans droit de lecture globale */
    public function visibleIds(Principal $principal): array
    {
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);
        if ($actor === null) {
            return [];
        }
        $ids = [$actor];

        $rows = $this->store->rows(
            'SELECT r.person_id, r.kind, p.birth_date FROM person_relations r JOIN people p ON p.id = r.person_id AND p.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND r.related_person_id = ?',
            [$principal->tenantId, $actor],
        );
        $today = $this->clock->today();
        foreach ($rows as $row) {
            $kind = RelationKind::tryFrom((string) $row['kind']);
            if ($kind === null || !$kind->grantsAccess()) {
                continue;
            }
            $learner = (string) $row['person_id'];
            $birthDate = $row['birth_date'] === null ? null : (string) $row['birth_date'];
            if (!Majority::isAdult($birthDate, $today) || $this->consents->hasConsent($principal->tenantId, $learner, ConsentChecker::PARENT_ACCESS)) {
                $ids[] = $learner;
            }
        }

        return array_values(array_unique([...$ids, ...$this->teaching->learnerIdsOf($principal->tenantId, $actor)]));
    }

    /** Vrai si l'appelant est la personne elle-même ou un de ses responsables autorisés. */
    public function actsFor(Principal $principal, string $personId): bool
    {
        return in_array($personId, $this->visibleIds($principal), true)
            && ($this->actors->personIdOf($principal->tenantId, $principal->userId) === $personId || $this->isGuardianOf($principal, $personId));
    }

    private function isGuardianOf(Principal $principal, string $personId): bool
    {
        $actor = $this->actors->personIdOf($principal->tenantId, $principal->userId);

        if ($actor === null) {
            return false;
        }
        foreach ($this->store->where('person_relations', $principal->tenantId, ['person_id' => $personId, 'related_person_id' => $actor]) as $row) {
            if (RelationKind::tryFrom((string) $row['kind'])?->grantsAccess() === true) {
                return true;
            }
        }

        return false;
    }
}
