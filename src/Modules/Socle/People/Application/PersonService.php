<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Domain\DuplicateMatcher;
use Scholaris\Modules\Socle\People\Domain\Names;
use Scholaris\Modules\Socle\People\Infrastructure\PdoActorDirectory;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Fiches des personnes : une personne par client, détection de doublons, matricule national, historique de scolarité. */
final class PersonService
{
    public function __construct(
        private readonly PdoStore $store,
        private readonly PeopleClock $clock,
        private readonly PdoActorDirectory $actors,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $personId): ?array
    {
        return $this->store->find('people', $tenantId, $personId);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $personId): array
    {
        return $this->find($tenantId, $personId) ?? throw HttpException::notFound('Personne introuvable.');
    }

    /**
     * @param array<string, mixed> $values identité validée
     * @return array<string, mixed>
     */
    public function create(string $tenantId, array $values): array
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $row = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'birth_date' => $values['birth_date'] ?? null,
            'birth_place' => $values['birth_place'] ?? null,
            'gender' => $values['gender'] ?? null,
            'nationality' => $values['nationality'] ?? null,
            'phone' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
            'locale' => $values['locale'] ?? 'fr',
            'national_id' => $values['national_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->guard(function () use ($tenantId, $row): void {
            $this->store->transaction(function () use ($tenantId, $row): void {
                $this->assertNationalIdFree($tenantId, $row['national_id'], null);
                $this->store->insert('people', $row);
                if ($row['national_id'] !== null) {
                    $this->storeIdentifier($tenantId, (string) $row['id'], 'national', '', (string) $row['national_id']);
                }
            });
        });

        return $this->get($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $personId, array $values): array
    {
        $before = $this->get($tenantId, $personId);
        $this->guard(function () use ($tenantId, $personId, $values, $before): void {
            $this->store->transaction(function () use ($tenantId, $personId, $values, $before): void {
                if (array_key_exists('national_id', $values) && $values['national_id'] !== $before['national_id']) {
                    $this->assertNationalIdFree($tenantId, $values['national_id'], $personId);
                    $this->store->delete('person_identifiers', $tenantId, ['person_id' => $personId, 'kind' => 'national']);
                    if ($values['national_id'] !== null) {
                        $this->storeIdentifier($tenantId, $personId, 'national', '', (string) $values['national_id']);
                    }
                }
                $this->store->update('people', $tenantId, $personId, $values + ['updated_at' => $this->clock->now()]);
            });
        });

        return $this->get($tenantId, $personId);
    }

    public function delete(string $tenantId, string $personId): void
    {
        $this->get($tenantId, $personId);
        if ($this->store->count('enrollments', $tenantId, ['person_id' => $personId]) > 0
            || $this->store->count('consents', $tenantId, ['person_id' => $personId]) > 0
            || $this->actors->hasAccount($tenantId, $personId)) {
            throw HttpException::conflict('Cette personne a des inscriptions, des consentements ou un compte : elle ne peut pas être supprimée.');
        }
        $this->store->transaction(function () use ($tenantId, $personId): void {
            foreach (['person_contacts', 'person_identifiers', 'person_documents', 'health_records', 'schooling_history'] as $table) {
                $this->store->delete($table, $tenantId, ['person_id' => $personId]);
            }
            $this->store->delete('person_relations', $tenantId, ['person_id' => $personId]);
            $this->store->delete('person_relations', $tenantId, ['related_person_id' => $personId]);
            $this->store->delete('people', $tenantId, ['id' => $personId]);
        });
    }

    /**
     * Candidats doublons d'une identité saisie, avec leurs critères concordants et un résumé de leur historique.
     *
     * @param array<string, mixed> $values
     * @return list<array{person: array<string, mixed>, criteria: list<string>, history: list<string>}>
     */
    public function duplicates(string $tenantId, array $values, ?string $excludeId = null): array
    {
        $clauses = [];
        $params = [$tenantId];
        if (isset($values['birth_date']) && is_string($values['birth_date'])) {
            $clauses[] = 'birth_date = ?';
            $params[] = $values['birth_date'];
        }
        $phone = Names::phoneKey(isset($values['phone']) ? (string) $values['phone'] : null);
        if ($phone !== null) {
            $clauses[] = 'phone LIKE ?';
            $params[] = '%' . substr($phone, -9);
        }
        if ($clauses === []) {
            return [];
        }

        $found = [];
        foreach ($this->store->rows('SELECT * FROM people WHERE tenant_id = ? AND (' . implode(' OR ', $clauses) . ') ORDER BY id LIMIT 200', $params) as $row) {
            if ($excludeId !== null && $row['id'] === $excludeId) {
                continue;
            }
            $criteria = DuplicateMatcher::criteria($values, $row);
            if ($criteria !== []) {
                $found[] = ['person' => $row, 'criteria' => $criteria, 'history' => $this->historySummary($tenantId, (string) $row['id'], $criteria)];
            }
        }
        usort($found, static fn (array $a, array $b): int => count($b['criteria']) <=> count($a['criteria']));

        return $found;
    }

    /**
     * @param list<string> $criteria
     * @return list<string> ex. « Ancien(ne) élève de Collège Les Palmiers (Tle D, 2025-2026) · même téléphone »
     */
    private function historySummary(string $tenantId, string $personId, array $criteria): array
    {
        $summary = [];
        $suffix = in_array('phone', $criteria, true) ? ' · même téléphone' : '';
        foreach ($this->store->where('schooling_history', $tenantId, ['person_id' => $personId], 'id', 5) as $entry) {
            $detail = array_filter([(string) ($entry['level_label'] ?? ''), (string) ($entry['year_label'] ?? '')], static fn (string $s): bool => $s !== '');
            $summary[] = 'Ancien(ne) élève de ' . $entry['school_name'] . ($detail !== [] ? ' (' . implode(', ', $detail) . ')' : '') . $suffix;
        }

        return $summary;
    }

    /**
     * @param list<string>|null $restrictTo null = tout le client
     * @return array{0: list<array<string, mixed>>, 1: ?string} lignes et curseur suivant
     */
    public function search(string $tenantId, ListQuery $query, ?array $restrictTo): array
    {
        if ($restrictTo === []) {
            return [[], null];
        }
        $where = ['tenant_id = ?'];
        $params = [$tenantId];
        $afterId = ListQuery::decodeCursor($query->cursor);
        if ($afterId !== null) {
            $where[] = 'id > ?';
            $params[] = $afterId;
        }
        if ($restrictTo !== null) {
            $where[] = 'id IN (' . implode(', ', array_fill(0, count($restrictTo), '?')) . ')';
            array_push($params, ...$restrictTo);
        }
        foreach (['phone', 'email', 'national_id', 'birth_date'] as $field) {
            if (isset($query->filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $query->filters[$field];
            }
        }
        if (isset($query->filters['last_name'])) {
            $where[] = 'LOWER(last_name) = ?';
            $params[] = mb_strtolower($query->filters['last_name']);
        }
        if (isset($query->filters['q']) && trim($query->filters['q']) !== '') {
            $like = '%' . strtr(trim($query->filters['q']), ['|' => '||', '%' => '|%', '_' => '|_']) . '%';
            $where[] = "(first_name LIKE ? ESCAPE '|' OR last_name LIKE ? ESCAPE '|' OR phone LIKE ? ESCAPE '|' OR email LIKE ? ESCAPE '|' OR national_id LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like, $like, $like);
        }

        $rows = $this->store->rows(
            sprintf('SELECT * FROM people WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), $query->perPage + 1),
            $params,
        );
        $next = null;
        if (count($rows) > $query->perPage) {
            $rows = array_slice($rows, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) $rows[count($rows) - 1]['id']);
        }

        return [$rows, $next];
    }

    /** @return array<string, mixed> */
    public function addContact(string $tenantId, string $personId, string $kind, string $value, ?string $label): array
    {
        $this->get($tenantId, $personId);
        $id = (string) new Ulid();
        $this->store->insert('person_contacts', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'kind' => $kind,
            'label' => $label, 'value' => $value, 'created_at' => $this->clock->now(),
        ]);

        return $this->store->find('person_contacts', $tenantId, $id) ?? [];
    }

    /**
     * Identifiant interne (par établissement) ou national ; le matricule national alimente aussi la fiche.
     *
     * @return array<string, mixed>
     */
    public function addIdentifier(string $tenantId, string $personId, string $kind, ?string $establishmentId, string $value): array
    {
        $person = $this->get($tenantId, $personId);
        if ($kind === 'national') {
            $this->update($tenantId, $personId, ['national_id' => $value]);
            $establishmentId = '';
        }
        $this->guard(function () use ($tenantId, $personId, $kind, $establishmentId, $value, $person): void {
            if ($kind === 'national' && $person['national_id'] === $value) {
                return;
            }
            if ($kind !== 'national') {
                $this->storeIdentifier($tenantId, $personId, $kind, (string) $establishmentId, $value);
            }
        });
        foreach ($this->store->where('person_identifiers', $tenantId, ['person_id' => $personId, 'kind' => $kind, 'value' => $value]) as $row) {
            return $row;
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function addHistory(string $tenantId, string $personId, string $school, ?string $level, ?string $year, ?string $outcome): array
    {
        $this->get($tenantId, $personId);
        $id = (string) new Ulid();
        $this->store->insert('schooling_history', [
            'id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId, 'enrollment_id' => null,
            'school_name' => $school, 'level_label' => $level, 'year_label' => $year, 'outcome' => $outcome,
            'source' => 'declared', 'created_at' => $this->clock->now(),
        ]);

        return $this->store->find('schooling_history', $tenantId, $id) ?? [];
    }

    public function storeIdentifier(string $tenantId, string $personId, string $kind, string $establishmentId, string $value): void
    {
        $this->store->insert('person_identifiers', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'person_id' => $personId, 'kind' => $kind,
            'establishment_id' => $establishmentId, 'value' => $value, 'created_at' => $this->clock->now(),
        ]);
    }

    private function assertNationalIdFree(string $tenantId, mixed $nationalId, ?string $exceptPersonId): void
    {
        if (!is_string($nationalId)) {
            return;
        }
        foreach ($this->store->where('people', $tenantId, ['national_id' => $nationalId], 'id', 2) as $row) {
            if ($row['id'] !== $exceptPersonId) {
                throw HttpException::conflict('Ce matricule national est déjà rattaché à une autre personne.');
            }
        }
    }

    /** @param callable(): void $write */
    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            if (str_starts_with((string) $e->getCode(), '23') || str_contains($e->getMessage(), 'UNIQUE')) {
                throw HttpException::conflict('Cette valeur existe déjà (identifiant ou matricule).');
            }
            throw $e;
        }
    }
}
