<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Documents\Domain\DocumentKinds;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentStatusWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Documents administratifs du primaire (certificat de scolarité, attestation, carte scolaire, fiche de renseignements, transfert
 * sortant) émis par le socle Documents : numéro continu, QR de vérification publique (/public/verify/{token}), registre. Les
 * variables viennent de l'inscription (classe, niveau, année), jamais de la saisie libre, sauf destination et motif du transfert.
 * Langue : celle du profil du niveau (section FR/EN), sinon français.
 */
final class AdministrativeDocuments
{
    public const PERMISSIONS = ['documents.issue', 'primary.enrol'];

    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly EnrollmentDirectory $enrollments,
        private readonly EnrollmentStatusWriter $statuses,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
        private readonly CurrentPeriod $periods,
        private readonly Access $access,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function issue(Principal $principal, string $establishmentId, string $kind, string $enrollmentId, ?string $locale): array
    {
        if (!in_array($kind, DocumentKinds::ENROLMENT_KINDS, true)) {
            throw HttpException::validation(['kind' => ['Valeurs possibles : ' . implode(', ', DocumentKinds::ENROLMENT_KINDS) . '.']]);
        }
        [$enrollment, $values, $lang] = $this->context($principal, $establishmentId, $enrollmentId, $locale);
        if (!in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
            throw HttpException::validation(['enrollment_id' => ['Ce document exige une inscription en cours.']]);
        }
        if ($kind === DocumentKinds::INFO_SHEET) {
            $guardians = $this->people->guarantorsOf($principal->tenantId, $enrollment->personId);
            $values['guardians'] = implode(', ', array_map(static fn ($g): string => $g->fullName(), $guardians));
            $values['contact_phone'] = $guardians === [] ? '' : (string) $guardians[0]->phone;
        }
        $document = $this->issuer->issue($principal->tenantId, $establishmentId, DocumentKinds::SOCLE_TYPES[$kind], $enrollment->personId, $values, $principal->userId, $lang);

        return self::resource($document, $kind, $enrollmentId);
    }

    /**
     * Transfert sortant : document de radiation puis inscription close (statut « transferred »). Si la clôture est refusée, le
     * document est révoqué : jamais d'exeat valide pour un élève encore inscrit.
     *
     * @return array<string, mixed>
     */
    public function transfer(Principal $principal, string $establishmentId, string $enrollmentId, string $destination, string $reason, string $effectiveOn, ?string $locale): array
    {
        [$enrollment, $values, $lang] = $this->context($principal, $establishmentId, $enrollmentId, $locale);
        if (!in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
            throw HttpException::validation(['enrollment_id' => ['Seule une inscription en cours peut être transférée.']]);
        }
        $values += ['destination' => $destination, 'reason' => $reason, 'effective_on' => $effectiveOn];
        $document = $this->issuer->issue($principal->tenantId, $establishmentId, DocumentKinds::SOCLE_TYPES[DocumentKinds::TRANSFER], $enrollment->personId, $values, $principal->userId, $lang);
        try {
            $this->statuses->changeStatus($principal->tenantId, $establishmentId, $enrollmentId, 'transferred');
        } catch (\Throwable $e) {
            $this->issuer->revoke($principal->tenantId, $document->id, 'Transfert non enregistré : l\'inscription n\'a pas pu être close.', $principal->userId);

            throw $e;
        }
        $this->access->audit($principal, 'primary.transfer.out', 'enrollment', $enrollmentId, ['status' => $enrollment->status], ['status' => 'transferred', 'document_id' => $document->id]);

        return self::resource($document, DocumentKinds::TRANSFER, $enrollmentId) + ['enrollment_status' => 'transferred'];
    }

    /** @return array{0: EnrollmentInfo, 1: array<string, scalar|null>, 2: string} */
    private function context(Principal $principal, string $establishmentId, string $enrollmentId, ?string $locale): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSIONS);
        $enrollment = $this->enrollments->find($principal->tenantId, $enrollmentId);
        if ($enrollment === null || $enrollment->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Inscription introuvable dans cet établissement.');
        }
        $this->access->assertWritableClass($principal, $perimeter, $enrollment->structureId, 'enrollment', $enrollmentId);
        $t = $principal->tenantId;
        $class = $enrollment->structureId === null ? null : $this->tree->structure($t, $establishmentId, $enrollment->structureId);
        $levelId = $enrollment->structureId === null ? null : $this->tree->levelOf($t, $establishmentId, $enrollment->structureId);
        $level = $levelId === null ? null : $this->tree->structure($t, $establishmentId, $levelId);
        $profile = $levelId === null ? null : $this->levels->profile($t, $establishmentId, $levelId);
        $period = $this->periods->at($t, $establishmentId, $this->clock->today());
        $year = $period !== null && $period->academicYearId === $enrollment->academicYearId && $period->academicYearLabel !== null ? $period->academicYearLabel : $enrollment->academicYearId;
        $lang = $locale ?? ($profile === null ? 'fr' : $profile->language);

        return [$enrollment, [
            'academic_year' => $year, 'class_name' => $class === null ? '' : $class->name, 'level_name' => $level === null ? '' : $level->name,
        ], $lang === 'en' ? 'en' : 'fr'];
    }

    /** @return array<string, mixed> */
    private static function resource(IssuedDocument $document, string $kind, string $enrollmentId): array
    {
        return [
            'id' => $document->id, 'number' => $document->number, 'kind' => $kind, 'type' => $document->type, 'status' => $document->status,
            'verification_token' => $document->verificationToken, 'enrollment_id' => $enrollmentId,
        ];
    }
}
