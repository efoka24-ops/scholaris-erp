<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Domain\RelationKind;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/**
 * Relations entre personnes. Une seule ligne par lien : « related_person_id est <kind> de person_id ».
 * Les deux vues (la personne comme enfant, la personne comme parent) sont lues sur cette même ligne, donc toujours cohérentes.
 */
final class RelationService
{
    public function __construct(private readonly PdoStore $store, private readonly PeopleClock $clock)
    {
    }

    /**
     * @return list<array{id: string, kind: string, as: string, person: array<string, mixed>}>
     *         as = « subject » (la personne a ce parent/tuteur…) ou « related » (la personne est parent/tuteur de l'autre)
     */
    public function forPerson(string $tenantId, string $personId): array
    {
        $out = [];
        $sql = 'SELECT r.id, r.kind, p.id AS pid, p.first_name, p.last_name, p.phone FROM person_relations r JOIN people p ON p.id = %s AND p.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND %s = ? ORDER BY r.id';
        foreach ([['subject', 'r.related_person_id', 'r.person_id'], ['related', 'r.person_id', 'r.related_person_id']] as [$as, $otherColumn, $ownColumn]) {
            foreach ($this->store->rows(sprintf($sql, $otherColumn, $ownColumn), [$tenantId, $personId]) as $row) {
                $out[] = [
                    'id' => (string) $row['id'],
                    'kind' => (string) $row['kind'],
                    'as' => $as,
                    'person' => ['id' => $row['pid'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'phone' => $row['phone']],
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function add(string $tenantId, string $personId, string $relatedId, RelationKind $kind, ?string $actorId): array
    {
        if ($personId === $relatedId) {
            throw HttpException::validation(['related_person_id' => ['Une personne ne peut pas être liée à elle-même.']]);
        }
        foreach ([$personId, $relatedId] as $id) {
            if ($this->store->find('people', $tenantId, $id) === null) {
                throw HttpException::validation(['related_person_id' => ['Personne introuvable dans ce client.']]);
            }
        }
        if ($this->store->count('person_relations', $tenantId, ['person_id' => $personId, 'related_person_id' => $relatedId, 'kind' => $kind->value]) > 0) {
            throw HttpException::conflict('Ce lien existe déjà.');
        }
        if (in_array($kind, [RelationKind::Parent, RelationKind::Guardian], true)) {
            foreach ([RelationKind::Parent, RelationKind::Guardian] as $inverse) {
                if ($this->store->count('person_relations', $tenantId, ['person_id' => $relatedId, 'related_person_id' => $personId, 'kind' => $inverse->value]) > 0) {
                    throw HttpException::conflict('Lien incohérent : cette personne est déjà le parent ou le tuteur de l\'autre.');
                }
            }
        }

        $id = (string) new Ulid();
        $this->store->insert('person_relations', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'related_person_id' => $relatedId,
            'kind' => $kind->value, 'created_by' => $actorId, 'created_at' => $this->clock->now(),
        ]);

        return $this->store->find('person_relations', $tenantId, $id) ?? [];
    }

    /** @return array<string, mixed> le lien supprimé */
    public function remove(string $tenantId, string $personId, string $relationId): array
    {
        $row = $this->store->find('person_relations', $tenantId, $relationId);
        if ($row === null || ($row['person_id'] !== $personId && $row['related_person_id'] !== $personId)) {
            throw HttpException::notFound('Lien introuvable.');
        }
        $this->store->delete('person_relations', $tenantId, ['id' => $relationId]);

        return $row;
    }

    public function hasGuarantor(string $tenantId, string $personId): bool
    {
        foreach ($this->store->where('person_relations', $tenantId, ['person_id' => $personId]) as $row) {
            if (in_array($row['kind'], ['financial_guarantor', 'parent', 'guardian'], true)) {
                return true;
            }
        }

        return false;
    }
}
