<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\IssuedStudentDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceSummary;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Documents du secondaire émis par la route POST /secondary/documents (T833) : les variables viennent de l'inscription, de la classe
 * ou de la facture du socle, jamais de la saisie libre (sauf destination, motif et date de l'exeat). Contrôles : droit du type et
 * périmètre de classe (403 journalisé hors périmètre), objet de l'établissement (404 sinon).
 * RG-DOC-4 : aucun document scolaire ne porte de montant ; seuls le relevé DOC-28 et l'attestation DOC-29 (documents financiers) en
 * portent, et l'attestation ne mentionne jamais un reste dû.
 */
final class StudentDocuments implements IssuedStudentDocuments
{
    /** Exeat seulement pour une inscription close par le parcours de transfert (jamais d'exeat valide pour un élève encore inscrit). */
    public const CLOSED = ['transferred', 'withdrawn'];
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];

    public function __construct(
        private readonly DocumentIssuance $issuance,
        private readonly DocumentTypeCatalogue $catalogue,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
        private readonly CurrentPeriod $periods,
        private readonly InvoiceIssuer $invoices,
        private readonly UserPersonLink $users,
        private readonly DocumentCatalog $documents,
        private readonly Access $access,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input champs validés (enrollment_id, class_id, invoice_id, destination, reason, effective_on, locale)
     * @return array<string, mixed>
     */
    public function issue(Principal $principal, string $establishmentId, string $kind, array $input): array
    {
        $type = $this->catalogue->find($kind);
        if ($type === null || !$type->direct) {
            throw HttpException::validation(['kind' => ['Cette nature de document n\'est pas émise par cette route : ' . $kind . '.']]);
        }
        $this->issuance->issuable($principal->tenantId, $establishmentId, $kind);
        $perimeter = $this->access->require($principal, $establishmentId, $type->permissions);
        $t = $principal->tenantId;
        $locale = $this->issuance->locale($t, $establishmentId, isset($input['locale']) ? (string) $input['locale'] : null);

        [$personId, $values, $ref] = match ($type->source) {
            SecondaryDocumentType::SOURCE_CLASS => $this->classValues($principal, $perimeter, $establishmentId, self::required($input, 'class_id'), $locale),
            SecondaryDocumentType::SOURCE_INVOICE => $this->invoiceValues($principal, $perimeter, $establishmentId, $kind, self::required($input, 'invoice_id'), $locale),
            default => $this->enrolmentValues($principal, $perimeter, $establishmentId, $kind, self::required($input, 'enrollment_id'), $input, $locale),
        };
        $document = $this->issuance->issue($t, $establishmentId, $kind, $personId, $values, $principal->userId, $locale);
        $this->access->audit($principal, 'secondary.document.issued', 'document', $document->id, null, ['kind' => $kind, 'doc' => $type->doc, 'number' => $document->number] + $ref);

        return self::resource($document, $type, $locale) + $ref;
    }

    /**
     * Documents du secondaire valides d'une personne (registre par élève), du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function issuedFor(string $tenantId, string $personId): array
    {
        $out = [];
        foreach ($this->documents->validFor($tenantId, $personId, 100) as $document) {
            $type = $this->catalogue->bySocleCode($document->type);
            if ($type !== null) {
                $out[] = self::resource($document, $type, null);
            }
        }

        return $out;
    }

    /**
     * Inscriptions d'une classe (en cours, ou closes par un transfert pour l'exeat), triées par nom.
     *
     * @return list<array{enrollment_id: string, person_id: string, name: string, status: string, internal_number: string|null}>
     */
    public function candidates(string $tenantId, string $establishmentId, string $classId): array
    {
        $out = [];
        $ids = $this->enrollments->personIdsInStructure($tenantId, $classId);
        foreach ($this->people->findMany($tenantId, $ids) as $person) {
            foreach ($this->enrollments->forPerson($tenantId, $person->id) as $enrollment) {
                if ($enrollment->structureId === $classId && $enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [...self::LIVE, ...self::CLOSED], true)) {
                    $out[] = [
                        'enrollment_id' => $enrollment->id, 'person_id' => $person->id, 'name' => mb_strtoupper($person->lastName) . ' ' . $person->firstName,
                        'status' => $enrollment->status, 'internal_number' => $enrollment->internalNumber,
                    ];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array<string, mixed> */
    public static function resource(IssuedDocument $document, SecondaryDocumentType $type, ?string $locale): array
    {
        return array_filter([
            'id' => $document->id, 'number' => $document->number, 'kind' => $type->kind, 'doc' => $type->doc, 'type' => $document->type,
            'status' => $document->status, 'qr' => $type->qr, 'verification_token' => $document->verificationToken, 'locale' => $locale,
            'subject_person_id' => $document->subjectPersonId,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: array<string, scalar|null>, 2: array<string, string>}
     */
    private function enrolmentValues(Principal $principal, Perimeter $perimeter, string $establishmentId, string $kind, string $enrollmentId, array $input, string $locale): array
    {
        $t = $principal->tenantId;
        $enrollment = $this->enrollments->find($t, $enrollmentId);
        if ($enrollment === null || $enrollment->tenantId !== $t || $enrollment->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Inscription introuvable dans cet établissement.');
        }
        $this->access->assertWritableClass($principal, $perimeter, $enrollment->structureId, 'enrollment', $enrollmentId);
        $isExeat = $kind === EntryDocumentTypes::EXEAT;
        if ($isExeat && !in_array($enrollment->status, self::CLOSED, true)) {
            throw HttpException::validation(['enrollment_id' => ['L\'exeat n\'est délivré qu\'après la clôture de l\'inscription par le transfert.']]);
        }
        if (!$isExeat && !in_array($enrollment->status, self::LIVE, true)) {
            throw HttpException::validation(['enrollment_id' => ['Ce document exige une inscription en cours.']]);
        }
        $en = $locale === 'en';
        $class = $enrollment->structureId === null ? null : $this->tree->structure($t, $establishmentId, $enrollment->structureId);
        $levelId = $enrollment->structureId === null ? null : $this->tree->levelOf($t, $establishmentId, $enrollment->structureId);
        $level = $levelId === null ? null : $this->tree->structure($t, $establishmentId, $levelId);
        $base = ['academic_year' => $this->yearLabel($t, $establishmentId, $enrollment->academicYearId), 'class_name' => $class === null ? '' : $class->name];
        $nationalId = $this->catalogue->nationalId($t, $establishmentId, $enrollment->personId);
        $nationalLine = $nationalId === null ? ($en ? 'National ID: pending' : 'Matricule : en attente') : ($en ? 'National ID: ' : 'Matricule : ') . $nationalId;
        $internal = $enrollment->internalNumber ?? '';

        $values = match ($kind) {
            EntryDocumentTypes::ENROLMENT_FORM => $base + [
                'level_name' => $level === null ? '' : $level->name, 'internal_number' => $internal, 'national_id_line' => $nationalLine,
                'guardians_block' => $this->guardiansBlock($t, $enrollment->personId, $en),
            ],
            EntryDocumentTypes::SCHOOL_CARD => $base + ['internal_number' => $internal, 'national_id_line' => $nationalLine],
            EntryDocumentTypes::EXEAT => $base + [
                'level_name' => $level === null ? '' : $level->name,
                'destination' => self::required($input, 'destination'), 'reason' => self::required($input, 'reason'), 'effective_on' => self::required($input, 'effective_on'),
            ],
            default => $base + ['level_name' => $level === null ? '' : $level->name],
        };

        return [$enrollment->personId, $values, ['enrollment_id' => $enrollmentId]];
    }

    /** @return array{0: string, 1: array<string, scalar|null>, 2: array<string, string>} */
    private function classValues(Principal $principal, Perimeter $perimeter, string $establishmentId, string $classId, string $locale): array
    {
        $t = $principal->tenantId;
        $class = $this->tree->structure($t, $establishmentId, $classId);
        if ($class === null || $class->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'structure', $classId);
        $issuer = $this->users->personIdForUser($t, $principal->userId);
        if ($issuer === null) {
            throw HttpException::validation(['class_id' => ['Votre compte n\'est rattaché à aucune fiche personne : la liste de classe est enregistrée à votre nom.']]);
        }
        $year = null;
        $rows = [];
        foreach ($this->people->findMany($t, $this->enrollments->personIdsInStructure($t, $classId)) as $person) {
            foreach ($this->enrollments->forPerson($t, $person->id) as $enrollment) {
                if ($enrollment->structureId === $classId && in_array($enrollment->status, self::LIVE, true)) {
                    $year ??= $enrollment->academicYearId;
                    $rows[] = [mb_strtoupper($person->lastName) . ' ' . $person->firstName, $enrollment->internalNumber ?? ''];
                    break;
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $lines = [];
        foreach ($rows as $i => [$name, $number]) {
            $lines[] = sprintf('%3d. %-40s %s', $i + 1, $name, $number);
        }
        $en = $locale === 'en';

        return [$issuer, [
            'academic_year' => $year === null ? '' : $this->yearLabel($t, $establishmentId, $year), 'class_name' => $class->name,
            'students_block' => implode("\n", $lines), 'total_line' => ($en ? 'Number of pupils: ' : 'Effectif : ') . count($rows),
        ], ['class_id' => $classId]];
    }

    /** @return array{0: string, 1: array<string, scalar|null>, 2: array<string, string>} */
    private function invoiceValues(Principal $principal, Perimeter $perimeter, string $establishmentId, string $kind, string $invoiceId, string $locale): array
    {
        $t = $principal->tenantId;
        $invoice = $this->invoices->find($t, $establishmentId, $invoiceId);
        if ($invoice === null || $invoice->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Facture introuvable dans cet établissement.');
        }
        $enrollment = $this->enrolmentOf($t, $establishmentId, $invoice);
        $classId = $enrollment === null ? null : $enrollment->structureId;
        if ($classId !== null || !$perimeter->wholeEstablishment) {
            $this->access->assertWritableClass($principal, $perimeter, $classId, 'invoice', $invoiceId);
        }
        $class = $classId === null ? null : $this->tree->structure($t, $establishmentId, $classId);
        $base = [
            'academic_year' => $enrollment === null ? '' : $this->yearLabel($t, $establishmentId, $enrollment->academicYearId),
            'class_name' => $class === null ? '' : $class->name,
        ];
        $en = $locale === 'en';
        if ($kind === EntryDocumentTypes::PAYMENT_ATTESTATION) {
            if ($invoice->paidXaf <= 0) {
                throw HttpException::validation(['invoice_id' => ['Aucun paiement constaté sur cette facture : pas d\'attestation.']]);
            }
            $line = $invoice->balanceXaf <= 0
                ? ($en ? 'that all the fees of the year, ' . self::xaf($invoice->netXaf) . ', have been paid in full (clearance).' : 's\'est acquitté(e) de l\'intégralité des frais de l\'année, soit ' . self::xaf($invoice->netXaf) . ' (quitus).')
                : ($en ? 'that the sum of ' . self::xaf($invoice->paidXaf) . ' has been paid towards school fees.' : 'avoir reçu la somme de ' . self::xaf($invoice->paidXaf) . ' au titre des frais de scolarité.');

            return [$invoice->subjectId, $base + ['attestation_line' => $line], ['invoice_id' => $invoiceId]];
        }
        $lines = [];
        foreach ($invoice->lines as $row) {
            $lines[] = sprintf('%-36s %14s %14s', mb_substr($row['label'], 0, 36), self::xaf($row['net_xaf']), self::xaf($row['paid_xaf']));
        }
        $installments = [];
        foreach ($invoice->installments as $row) {
            $installments[] = sprintf('%-10s %-26s %14s %14s', $row['due_on'], mb_substr($row['label'], 0, 26), self::xaf($row['amount_xaf']), self::xaf($row['paid_xaf']));
        }
        $totals = $en
            ? sprintf('Total due: %s · Paid: %s · Balance: %s', self::xaf($invoice->netXaf), self::xaf($invoice->paidXaf), self::xaf(max(0, $invoice->balanceXaf)))
            : sprintf('Total dû : %s · Payé : %s · Reste : %s', self::xaf($invoice->netXaf), self::xaf($invoice->paidXaf), self::xaf(max(0, $invoice->balanceXaf)));

        return [$invoice->subjectId, $base + [
            'lines_block' => implode("\n", $lines), 'installments_block' => implode("\n", $installments), 'totals_line' => $totals,
        ], ['invoice_id' => $invoiceId]];
    }

    private function enrolmentOf(string $tenantId, string $establishmentId, InvoiceSummary $invoice): ?EnrollmentInfo
    {
        if ($invoice->enrollmentId !== null) {
            $enrollment = $this->enrollments->find($tenantId, $invoice->enrollmentId);
            if ($enrollment !== null && $enrollment->establishmentId === $establishmentId) {
                return $enrollment;
            }
        }
        foreach ($this->enrollments->forPerson($tenantId, $invoice->subjectId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true)) {
                return $enrollment;
            }
        }

        return null;
    }

    private function guardiansBlock(string $tenantId, string $learnerId, bool $en): string
    {
        $lines = [];
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $lines[] = $guardian->fullName() . ($guardian->phone === null || $guardian->phone === '' ? '' : ' · ' . $guardian->phone);
        }

        return $lines === [] ? ($en ? 'Not provided' : 'Non renseigné') : implode("\n", $lines);
    }

    private function yearLabel(string $tenantId, string $establishmentId, string $academicYearId): string
    {
        $period = $this->periods->at($tenantId, $establishmentId, $this->clock->today());

        return $period !== null && $period->academicYearId === $academicYearId && $period->academicYearLabel !== null ? $period->academicYearLabel : $academicYearId;
    }

    private static function xaf(int $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }

    /** @param array<string, mixed> $input */
    private static function required(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw HttpException::validation([$field => ['Champ obligatoire pour cette nature de document.']]);
        }

        return $value;
    }
}
