<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Pont vers les documents de l'IPES : émission de DOC-20 à 23 pour l'étudiant d'une convention (numéro, langue et profil de
 * l'école par Documents), noms des personnes (People) et quitus financier émis (DOC-30 valide, RG-STG-1).
 */
final class InternshipDocuments
{
    public const QUITUS = 'DOC-30';

    public function __construct(
        private readonly HigherDocumentIssuer $issuer,
        private readonly IssuedDocuments $issued,
        private readonly PersonDirectory $people,
    ) {
    }

    public function nameOf(string $tenantId, string $personId): string
    {
        return $this->people->find($tenantId, $personId)?->fullName() ?? '';
    }

    public function quitusIssued(string $tenantId, string $personId): bool
    {
        foreach ($this->issued->forPerson($tenantId, $personId) as $document) {
            if (($document['code'] ?? null) === self::QUITUS && ($document['status'] ?? null) === 'valid') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $internship ligne hi_internships
     * @param array<string, scalar|null> $data variables propres au type
     * @return string identifiant du document émis
     */
    public function issue(string $tenantId, string $establishmentId, string $code, array $internship, array $data, string $issuedBy): string
    {
        $data += ['internship_ref' => 'STG-' . substr((string) $internship['id'], -8)];
        $document = $this->issuer->issueFor(
            $tenantId,
            $establishmentId,
            $code,
            (string) $internship['person_id'],
            (string) $internship['school_id'],
            (string) $internship['program_id'],
            (string) $internship['level_id'],
            $data,
            $issuedBy,
        );

        return (string) ($document['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $internship
     * @param array<string, mixed> $company
     * @return array<string, scalar|null>
     */
    public static function internshipData(array $internship, array $company): array
    {
        return [
            'company_name' => (string) $company['name'], 'company_address' => trim(($company['address'] ?? '') . ' ' . ($company['city'] ?? '')),
            'internship_subject' => (string) $internship['subject'], 'starts_on' => (string) $internship['starts_on'], 'ends_on' => (string) $internship['ends_on'],
            'company_tutor' => (string) $internship['company_tutor_name'], 'supervisor_name' => (string) $internship['supervisor_name'],
        ];
    }
}
