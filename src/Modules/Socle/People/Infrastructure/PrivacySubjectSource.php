<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Audit\Contracts\Pseudonym;
use Scholaris\Modules\Socle\Audit\Contracts\SubjectDataSource;

/**
 * Données People d'une personne concernée. Les données de santé ne sont JAMAIS renvoyées ici (export client uniquement) ;
 * l'effacement les supprime. Les inscriptions et les consentements sont conservés (obligation de preuve) mais ne portent
 * plus d'identité une fois la personne anonymisée.
 */
final class PrivacySubjectSource implements SubjectDataSource
{
    public function __construct(private readonly ConnectionFactory $connections, private readonly FileStorage $files)
    {
    }

    public function name(): string
    {
        return 'people';
    }

    public function collect(string $tenantId, string $personId): array
    {
        return [
            'people' => $this->rows('SELECT id, first_name, last_name, phone, email, locale, birth_date, birth_place, gender, nationality, photo_path, national_id, created_at, updated_at FROM people WHERE tenant_id = ? AND id = ?', [$tenantId, $personId]),
            'person_contacts' => $this->rows('SELECT id, kind, label, value, created_at FROM person_contacts WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'person_identifiers' => $this->rows('SELECT id, kind, establishment_id, value, created_at FROM person_identifiers WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'person_relations' => $this->rows('SELECT id, person_id, related_person_id, kind, created_at FROM person_relations WHERE tenant_id = ? AND (person_id = ? OR related_person_id = ?) ORDER BY id', [$tenantId, $personId, $personId]),
            'person_documents' => $this->rows('SELECT id, kind, size_bytes, mime_type, expires_on, created_at FROM person_documents WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'consents' => $this->rows('SELECT id, purpose, granted_at, revoked_at FROM consents WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'schooling_history' => $this->rows('SELECT id, school_name, level_label, year_label, outcome, source, created_at FROM schooling_history WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
            'enrollments' => $this->rows('SELECT id, establishment_id, structure_id, academic_year_id, status, internal_number, created_at FROM enrollments WHERE tenant_id = ? AND person_id = ? ORDER BY id', [$tenantId, $personId]),
        ];
    }

    public function anonymize(string $tenantId, string $personId): int
    {
        $touched = 0;

        // Fichiers téléversés : supprimés du stockage avant d'effacer leur référence.
        foreach ($this->rows('SELECT storage_path FROM person_documents WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]) as $document) {
            $this->files->delete((string) $document['storage_path']);
        }

        $touched += $this->run('DELETE FROM person_documents WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);
        $touched += $this->run('DELETE FROM health_records WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);
        $touched += $this->run('DELETE FROM person_contacts WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);
        $touched += $this->run('DELETE FROM person_relations WHERE tenant_id = ? AND (person_id = ? OR related_person_id = ?)', [$tenantId, $personId, $personId]);
        $touched += $this->run("UPDATE person_identifiers SET value = 'anonymise' WHERE tenant_id = ? AND person_id = ?", [$tenantId, $personId]);
        $touched += $this->run('UPDATE schooling_history SET school_name = NULL WHERE tenant_id = ? AND person_id = ?', [$tenantId, $personId]);
        $touched += $this->run(
            'UPDATE people SET first_name = ?, last_name = ?, phone = NULL, email = NULL, birth_date = NULL, birth_place = NULL, gender = NULL, nationality = NULL, photo_path = NULL, national_id = NULL WHERE tenant_id = ? AND id = ?',
            ['Anonyme', Pseudonym::of($tenantId, $personId), $tenantId, $personId],
        );

        return $touched;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @param list<scalar|null> $params */
    private function run(string $sql, array $params): int
    {
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
