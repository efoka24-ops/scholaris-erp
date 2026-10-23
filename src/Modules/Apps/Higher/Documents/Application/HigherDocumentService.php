<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Application;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\TeachingLanguageResolver;
use Scholaris\Modules\Apps\Higher\Documents\Infrastructure\DocumentsRepository;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssueOptions;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Documents de l'IPES : profil documentaire versionné de chaque école (RG-ORG-4, RG-DOC-6), émission par le socle avec numéro
 * RG-DOC-1 par école (jeton {ECOLE}, S16), signataire du type et de l'école (S17), langue de la filière ou de l'école (RG-DOC-8),
 * blocage pour impayé des seuls types bloquables (RG-DOC-7), registre, annulation jamais suppression (RG-DOC-5) et duplicata
 * avec numéro d'origine (RG-DOC-4). La page publique de vérification est celle du socle (RG-DOC-2 : sans les notes).
 */
final class HigherDocumentService implements HigherDocumentIssuer, IssuedDocuments
{
    public const ISSUE = 'documents.issue';
    public const READ = 'higher.document.read';
    public const TEMPLATES = 'documents.templates.manage';
    public const QUITUS = 'higher.quitus.issue';
    public const COLUMNS = ['school' => 'school_id', 'program' => 'program_id', 'level' => 'level_id'];
    public const LANGUAGE_RESOLVERS = 'higher.documents.language_resolvers.';
    public const OVERRIDABLE = ['holder_matricule', 'academic_year', 'issue_place', 'program_name', 'level_name'];
    public const COLOR = '/^#[0-9A-Fa-f]{6}$/';
    public const VALID = 'valid';
    public const CANCELLED = 'cancelled';
    public const REPLACED = 'replaced';

    public function __construct(
        private readonly HigherDocumentCatalog $catalog,
        private readonly DocumentsRepository $repository,
        private readonly ExtendedDocumentIssuer $issuer,
        private readonly ScopedBySchool $scoped,
        private readonly OrgUnitTree $tree,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitSettingsReader $settings,
        private readonly Container $container,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** Premier périmètre non vide parmi les permissions ; 403 si aucune. */
    public function perimeter(Principal $principal, string $establishmentId, string ...$permissions): Perimeter
    {
        foreach ($permissions as $permission) {
            $perimeter = $this->scoped->perimeter($principal, $permission, $establishmentId);
            if (!$perimeter->isEmpty()) {
                return $perimeter;
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $permissions) . '.');
    }

    /** @return array<string, mixed> */
    public function profile(Principal $principal, string $establishmentId, string $schoolId): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::READ, self::TEMPLATES, self::ISSUE);
        $this->scoped->assertReadable($perimeter, ['school' => $schoolId]);
        $row = $this->repository->latestProfile($principal->tenantId, $establishmentId, $schoolId) ?? throw HttpException::notFound('Aucun profil documentaire pour cette école.');

        return self::profileResource($row) + ['versions' => array_map(self::profileResource(...), $this->repository->profileVersions($principal->tenantId, $establishmentId, $schoolId))];
    }

    /**
     * Nouvelle version du profil documentaire de l'école ; les documents déjà émis gardent la version de leur émission.
     *
     * @param array{school_code: string, display_name: string, header_text: ?string, logo_url: ?string, primary_color: ?string, default_locale: ?string} $input
     * @return array<string, mixed>
     */
    public function putProfile(Principal $principal, string $establishmentId, string $schoolId, array $input): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::TEMPLATES);
        $this->scoped->assertWritable($principal, $perimeter, ['school' => $schoolId], 'hi_document_school_profile', $schoolId);
        $units = $this->tree->descendantsOf($principal->tenantId, $establishmentId, $schoolId);
        if ($units === [] || $this->tree->ancestorsOf($principal->tenantId, $establishmentId, $schoolId) !== []) {
            throw HttpException::validation(['school_id' => ['École introuvable dans cet établissement.']]);
        }
        $previous = $this->repository->latestProfile($principal->tenantId, $establishmentId, $schoolId);
        $id = (string) new Ulid();
        $row = [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'school_id' => $schoolId,
            'version' => $previous === null ? 1 : (int) $previous['version'] + 1, 'school_code' => $input['school_code'],
            'display_name' => $input['display_name'], 'header_text' => $input['header_text'], 'logo_url' => $input['logo_url'],
            'primary_color' => $input['primary_color'] ?? '#1F3A5F', 'default_locale' => $input['default_locale'] ?? 'fr',
            'created_by' => $principal->userId, 'created_at' => $this->now(),
        ];
        $this->repository->insertProfile($row);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.document_profile.versioned', 'hi_document_school_profile', $id, $previous === null ? null : ['version' => (int) $previous['version']], ['version' => $row['version'], 'school_id' => $schoolId]);

        return $this->profile($principal, $establishmentId, $schoolId);
    }

    /**
     * Émission demandée par l'API Higher : permission documents.issue (et higher.quitus.issue pour le quitus) dans le périmètre.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, mixed>
     */
    public function issue(Principal $principal, string $establishmentId, string $code, string $personId, string $schoolId, ?string $programId, ?string $levelId, array $data, ?string $locale): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::ISSUE);
        if ($code === 'DOC-30') {
            $this->perimeter($principal, $establishmentId, self::QUITUS);
        }
        $this->scoped->assertWritable($principal, $perimeter, ['school' => $schoolId, 'program' => $programId, 'level' => $levelId], 'hi_issued_document', $personId);

        return $this->issueFor($principal->tenantId, $establishmentId, $code, $personId, $schoolId, $programId, $levelId, $data, $principal->userId, $locale);
    }

    public function issueFor(
        string $tenantId,
        string $establishmentId,
        string $code,
        string $personId,
        string $schoolId,
        ?string $programId,
        ?string $levelId,
        array $data,
        string $issuedBy,
        ?string $locale = null,
    ): array {
        $type = $this->catalog->find($code) ?? throw HttpException::validation(['code' => ['Type de document inconnu : ' . $code . '.']]);
        if ($type->issuedBySocle) {
            throw HttpException::validation(['code' => ['Ce document est produit par le socle (reçu de caisse : /receipts).']]);
        }
        $profile = $this->repository->latestProfile($tenantId, $establishmentId, $schoolId)
            ?? throw HttpException::validation(['school_id' => ['Aucun profil documentaire pour cette école : renseignez son code, son en-tête et sa langue.']]);
        $unknown = array_diff(array_map('strval', array_keys($data)), [...$type->variables, ...self::OVERRIDABLE]);
        if ($unknown !== []) {
            throw HttpException::validation(['data' => ['Variables non déclarées pour ' . $code . ' : ' . implode(', ', $unknown) . '.']]);
        }
        if (preg_match(HigherDocumentCatalog::FORBIDDEN_TITLE, $type->labelFr . ' ' . $type->labelEn) === 1) {
            throw HttpException::validation(['code' => ['Intitulé interdit (RG-EXN-3).']]);
        }
        $locale ??= $this->languageOf($tenantId, $establishmentId, $programId) ?? (string) $profile['default_locale'];
        $locale = $locale === 'en' ? 'en' : 'fr';

        $values = $this->brandingData($tenantId, $establishmentId, $type, $profile, $personId, $schoolId, $programId, $levelId, $locale);
        foreach ($data as $key => $value) {
            $values[(string) $key] = $value;
        }
        $document = $this->issuer->issueWithOptions(
            $tenantId,
            $establishmentId,
            $type->socleCode(),
            $personId,
            $values,
            $issuedBy,
            $locale,
            new IssueOptions((string) $profile['school_code'], $schoolId, null, $type->blockable),
        );

        $id = (string) new Ulid();
        $this->repository->insertIssued([
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'document_id' => $document->id, 'doc_code' => $code,
            'socle_type' => $type->socleCode(), 'doc_number' => $document->number, 'verification_token' => $document->verificationToken,
            'person_id' => $personId, 'school_id' => $schoolId, 'program_id' => $programId, 'level_id' => $levelId, 'locale' => $locale,
            'profile_version' => (int) $profile['version'], 'doc_status' => self::VALID, 'cancel_reason' => null, 'replaced_by' => null,
            'cancelled_by' => null, 'cancelled_at' => null, 'duplicate_count' => 0, 'last_duplicate_at' => null, 'issued_by' => $issuedBy,
            'issued_at' => $this->now(),
        ]);

        return self::issuedResource($this->repository->issued($tenantId, $establishmentId, $id) ?? []);
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function index(Principal $principal, string $establishmentId, array $filters): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::READ, self::ISSUE);
        $rows = $this->repository->issuedList($principal->tenantId, $establishmentId, $filters, $this->scoped->sqlFilter($perimeter, self::COLUMNS));

        return array_map(self::issuedResource(...), $rows);
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::READ, self::ISSUE);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->scoped->assertReadable($perimeter, self::scopeOf($row));

        return self::issuedResource($row);
    }

    /**
     * Annulation d'un document émis par erreur (RG-DOC-5) : révoqué au socle avec son motif, jamais supprimé ; avec `replaced_by`,
     * l'état devient « remplacé » par un autre document valide du même étudiant.
     *
     * @return array<string, mixed>
     */
    public function cancel(Principal $principal, string $establishmentId, string $id, string $reason, ?string $replacedBy): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::ISSUE);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->scoped->assertWritable($principal, $perimeter, self::scopeOf($row), 'hi_issued_document', $id);
        if ($row['doc_status'] !== self::VALID) {
            throw HttpException::conflict('Ce document est déjà annulé.');
        }
        $replacement = null;
        if ($replacedBy !== null) {
            $replacement = $this->repository->issued($principal->tenantId, $establishmentId, $replacedBy);
            if ($replacement === null || $replacement['id'] === $id || $replacement['doc_status'] !== self::VALID || $replacement['person_id'] !== $row['person_id']) {
                throw HttpException::validation(['replaced_by' => ['Le remplaçant doit être un autre document valide du même étudiant.']]);
            }
        }
        $this->issuer->revoke($principal->tenantId, (string) $row['document_id'], $replacement === null ? $reason : $reason . ' (remplacé par ' . $replacement['doc_number'] . ')', $principal->userId);
        $this->repository->updateIssued($principal->tenantId, $establishmentId, $id, [
            'doc_status' => $replacedBy === null ? self::CANCELLED : self::REPLACED, 'cancel_reason' => $reason, 'replaced_by' => $replacedBy,
            'cancelled_by' => $principal->userId, 'cancelled_at' => $this->now(),
        ]);

        return self::issuedResource($this->row($principal->tenantId, $establishmentId, $id));
    }

    /**
     * Réimpression (RG-DOC-4) : mention « Duplicata » datée, numéro d'origine conservé, version de modèle de l'émission.
     * Le rendu est celui du socle (`render_path`) ; l'application trace chaque duplicata.
     *
     * @return array<string, mixed>
     */
    public function duplicate(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->perimeter($principal, $establishmentId, self::ISSUE);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->scoped->assertWritable($principal, $perimeter, self::scopeOf($row), 'hi_issued_document', $id);
        if ($row['doc_status'] !== self::VALID) {
            throw HttpException::conflict('Un document annulé ou remplacé ne peut pas être réimprimé.');
        }
        $moment = $this->moment();
        $this->repository->updateIssued($principal->tenantId, $establishmentId, $id, ['duplicate_count' => (int) $row['duplicate_count'] + 1, 'last_duplicate_at' => $moment->format(DATE_ATOM)]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.document.duplicated', 'hi_issued_document', $id, null, ['number' => $row['doc_number']]);

        return self::issuedResource($this->row($principal->tenantId, $establishmentId, $id)) + [
            'duplicate' => [
                'mention' => $row['locale'] === 'en' ? 'Duplicate — reprinted on ' . $moment->format('Y-m-d') : 'Duplicata — réimprimé le ' . $moment->format('d/m/Y'),
                'original_number' => (string) $row['doc_number'],
                'render_path' => '/api/v1/documents/' . $row['document_id'] . '/duplicate',
            ],
        ];
    }

    /** @return list<array<string, mixed>> documents valides ou non d'une personne (portail) */
    public function forPerson(string $tenantId, string $personId): array
    {
        return array_map(self::issuedResource(...), $this->repository->issuedForPerson($tenantId, $personId));
    }

    private function languageOf(string $tenantId, string $establishmentId, ?string $programId): ?string
    {
        if ($programId === null) {
            return null;
        }
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::LANGUAGE_RESOLVERS)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $resolver = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($resolver instanceof TeachingLanguageResolver) {
                    $language = $resolver->languageOf($tenantId, $establishmentId, $programId);
                    if ($language === 'fr' || $language === 'en') {
                        return $language;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, scalar|null>
     */
    private function brandingData(string $tenantId, string $establishmentId, HigherDocumentType $type, array $profile, string $personId, string $schoolId, ?string $programId, ?string $levelId, string $locale): array
    {
        $matricule = '';
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->internalNumber !== null) {
                $matricule = $enrollment->internalNumber;
            }
        }
        $place = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, 'higher.documents.issue_place', '');

        return [
            'school_name' => (string) $profile['display_name'], 'school_code' => (string) $profile['school_code'],
            'school_header' => (string) ($profile['header_text'] ?? ''), 'school_logo_url' => (string) ($profile['logo_url'] ?? ''),
            'school_color' => (string) $profile['primary_color'], 'document_title' => $type->label($locale), 'holder_matricule' => $matricule,
            'program_name' => $programId !== null ? ($this->tree->structure($tenantId, $establishmentId, $programId)->name ?? '') : '',
            'level_name' => $levelId !== null ? ($this->tree->structure($tenantId, $establishmentId, $levelId)->name ?? '') : '',
            'academic_year' => '', 'issue_place' => is_string($place) ? $place : '',
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->issued($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Document introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?string>
     */
    private static function scopeOf(array $row): array
    {
        return [
            'school' => isset($row['school_id']) ? (string) $row['school_id'] : null,
            'program' => isset($row['program_id']) ? (string) $row['program_id'] : null,
            'level' => isset($row['level_id']) ? (string) $row['level_id'] : null,
        ];
    }

    private function moment(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'));
    }

    private function now(): string
    {
        return $this->moment()->format(DATE_ATOM);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function profileResource(array $row): array
    {
        return [
            'school_id' => (string) $row['school_id'], 'version' => (int) $row['version'], 'school_code' => (string) $row['school_code'],
            'display_name' => (string) $row['display_name'], 'header_text' => $row['header_text'], 'logo_url' => $row['logo_url'],
            'primary_color' => (string) $row['primary_color'], 'default_locale' => (string) $row['default_locale'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function issuedResource(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''), 'document_id' => (string) ($row['document_id'] ?? ''), 'code' => (string) ($row['doc_code'] ?? ''),
            'number' => (string) ($row['doc_number'] ?? ''), 'verification_token' => (string) ($row['verification_token'] ?? ''),
            'person_id' => (string) ($row['person_id'] ?? ''), 'school_id' => (string) ($row['school_id'] ?? ''), 'program_id' => $row['program_id'] ?? null,
            'level_id' => $row['level_id'] ?? null, 'locale' => (string) ($row['locale'] ?? 'fr'), 'profile_version' => (int) ($row['profile_version'] ?? 0),
            'status' => (string) ($row['doc_status'] ?? ''), 'cancel_reason' => $row['cancel_reason'] ?? null, 'replaced_by' => $row['replaced_by'] ?? null,
            'duplicate_count' => (int) ($row['duplicate_count'] ?? 0), 'issued_at' => (string) ($row['issued_at'] ?? ''),
        ];
    }
}
