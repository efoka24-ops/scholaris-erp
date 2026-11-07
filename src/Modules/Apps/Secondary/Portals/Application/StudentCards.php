<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Portals\Application;

use DI\Container;
use LogicException;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\IssuedStudentDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFactsReader;
use Scholaris\Modules\Apps\Secondary\Portals\Contracts\FamilyInvoices;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfile;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Fiche d'un élève pour les portails du secondaire (T834), composée par contrats seulement : inscription en cours dans
 * l'établissement (classe, niveau, année, statut), matricule enregistré ou en attente (JAMAIS sa valeur, D5), documents du secondaire
 * (numéro et jeton de vérification, sans contenu ni note, RG-DOC-2), solde (socle Billing) et factures payables si un sous-module les
 * fournit. Accès restreint (élève majeur sans consentement `parent_access`) : identité, inscription et solde seulement.
 * Blocage pour impayé (privé) : les documents sont retenus, jamais l'inscription ni le solde (RG-FIN-1, RG-DOC-4).
 */
final class StudentCards
{
    public const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];
    public const INVOICES_PREFIX = 'secondary.family_invoices.';

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly CurrentPeriod $periods,
        private readonly DebtorDirectory $debtors,
        private readonly IssuedStudentDocuments $documents,
        private readonly StudentFactsReader $facts,
        private readonly SecondaryClock $clock,
        private readonly Container $container,
    ) {
    }

    /** Inscription en cours (active ou sous condition) de la personne dans l'établissement, ou null. */
    public function liveEnrollment(string $tenantId, string $establishmentId, string $personId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true)) {
                return $enrollment;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function build(string $tenantId, EstablishmentProfile $profile, PersonSummary $person, EnrollmentInfo $enrollment, bool $full, bool $withInvoices): array
    {
        $establishmentId = $profile->establishmentId;
        $class = $enrollment->structureId === null ? null : $this->tree->structure($tenantId, $establishmentId, $enrollment->structureId);
        $levelId = $enrollment->structureId === null ? null : $this->tree->levelOf($tenantId, $establishmentId, $enrollment->structureId);
        $level = $levelId === null ? null : $this->tree->structure($tenantId, $establishmentId, $levelId);
        $period = $this->periods->at($tenantId, $establishmentId, $this->clock->today());
        $card = [
            'person' => ['id' => $person->id, 'name' => $person->fullName()],
            'access' => $full ? 'full' : 'restricted',
            'enrollment' => [
                'id' => $enrollment->id, 'status' => $enrollment->status,
                'class' => $class === null ? null : ['id' => $class->id, 'name' => $class->name],
                'level' => $level === null ? null : ['id' => $level->id, 'name' => $level->name],
                'academic_year' => $period !== null && $period->academicYearId === $enrollment->academicYearId && $period->academicYearLabel !== null ? $period->academicYearLabel : $enrollment->academicYearId,
            ],
            'national_id_registered' => $this->facts->nationalId($tenantId, $establishmentId, $person->id) !== null,
            'balance_xaf' => max(0, $this->debtors->balanceXaf($tenantId, $establishmentId, $person->id)),
            'fees_mode' => $profile->isActive(Modules::FEES_PRIVATE) ? Modules::FEES_PRIVATE : ($profile->isActive(Modules::FEES_PUBLIC_CATALOG) ? Modules::FEES_PUBLIC_CATALOG : null),
        ];
        if (!$full) {
            return $card;
        }
        $blocked = $this->debtors->isBlocked($tenantId, $establishmentId, $person->id, 'documents');
        $card['documents'] = ['blocked' => $blocked, 'items' => $blocked ? [] : $this->documents->issuedFor($tenantId, $person->id)];
        if ($withInvoices) {
            $card['invoices'] = $this->invoices($tenantId, $establishmentId, $person->id);
        }

        return $card;
    }

    /** @return list<array<string, mixed>> */
    public function invoices(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $out = [];
        foreach ($this->providers() as $provider) {
            foreach ($provider->openFor($tenantId, $establishmentId, $learnerId) as $invoice) {
                if ($invoice->subjectId !== $learnerId || $invoice->establishmentId !== $establishmentId || $invoice->balanceXaf <= 0 || isset($out[$invoice->id])) {
                    continue;
                }
                $out[$invoice->id] = [
                    'id' => $invoice->id, 'reference' => $invoice->reference, 'net_xaf' => $invoice->netXaf, 'paid_xaf' => $invoice->paidXaf, 'balance_xaf' => $invoice->balanceXaf,
                    'installments' => array_map(static fn (array $i): array => ['label' => $i['label'], 'due_on' => $i['due_on'], 'amount_xaf' => $i['amount_xaf'], 'paid_xaf' => $i['paid_xaf']], $invoice->installments),
                ];
            }
        }

        return array_values($out);
    }

    /** @return list<FamilyInvoices> */
    private function providers(): array
    {
        $names = array_values(array_filter($this->container->getKnownEntryNames(), static fn (string $name): bool => str_starts_with($name, self::INVOICES_PREFIX)));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if (!$provider instanceof FamilyInvoices) {
                    throw new LogicException('Fournisseur de factures invalide sous ' . $name . '.');
                }
                $out[] = $provider;
            }
        }

        return $out;
    }
}
