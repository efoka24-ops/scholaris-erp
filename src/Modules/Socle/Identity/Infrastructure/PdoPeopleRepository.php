<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Support\Ulid;

/** Fiche personne minimale (le module People l'étendra en phase 5). */
final class PdoPeopleRepository extends PdoRepository
{
    public function insert(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $now): string
    {
        $id = (string) new Ulid();
        $this->run(
            'INSERT INTO people (id, tenant_id, first_name, last_name, phone, email, locale, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $firstName, $lastName, $phone, $email, $locale, $now],
        );

        return $id;
    }

    /**
     * Fiches du client portant ce téléphone ou cette adresse (identifiants déjà normalisés).
     *
     * @return list<array{id: string, first_name: string, last_name: string}>
     */
    public function withContact(string $tenantId, ?string $phone, ?string $email): array
    {
        $clauses = [];
        $params = [$tenantId];
        foreach (['phone' => $phone, 'email' => $email] as $column => $value) {
            if ($value !== null) {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        if ($clauses === []) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
        ], $this->rows('SELECT id, first_name, last_name FROM people WHERE tenant_id = ? AND (' . implode(' OR ', $clauses) . ') ORDER BY id', $params));
    }

    /** @return array{id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string}|null */
    public function find(string $tenantId, string $personId): ?array
    {
        $row = $this->one('SELECT id, first_name, last_name, phone, email, locale FROM people WHERE tenant_id = ? AND id = ?', [$tenantId, $personId]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => self::nullableString($row['phone']),
            'email' => self::nullableString($row['email']),
            'locale' => (string) $row['locale'],
        ];
    }
}
