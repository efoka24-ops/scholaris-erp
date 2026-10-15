<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Admissions;

use Scholaris\Modules\Apps\Higher\Admissions\Application\AdmissionsClock;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentClock;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Higher\Academic\CurriculumTestCase;
use Tests\Support\Higher\Billing\PersonLinks;

/**
 * Base des tests ADM et INS : vrai paramétrage académique (écoles, filières, autorisations, tutelles) et vraie maquette publiée
 * (Marie N., L1, 30 crédits) d'H1 ; doublures des contrats de facturation (D4), comptes de scolarité, documents, notifications,
 * comptes utilisateurs. Horloges figées au 13 octobre 2026.
 *
 * Acteurs du client A : `scol` (R07 scolarité, école EC), `dir` (R04 directeur de l'école EC), `scol-it` (scolarité de l'école IT,
 * hors périmètre), `cand` (candidate en ligne, fiche `p-marie`).
 */
abstract class EntryTestCase extends CurriculumTestCase
{
    public const SCOL = 'scol';
    public const DIR = 'dir';
    public const SCOL_IT = 'scol-it';
    public const CAND = 'cand';
    public const MARIE = 'p-marie';

    protected FakeBilling $billing;
    protected EntryFakes $fakes;
    protected InMemoryPeople $people;
    protected PersonLinks $links;
    protected InMemoryEnrollments $socleEnrollments;
    protected AdmissionsClock $admissionsClock;
    protected EnrolmentClock $enrolmentClock;
    /** @var array{id: string, units: array<string, string>, elements: array<string, string>} */
    protected array $curriculum;

    protected function bootEntry(): void
    {
        $this->billing = new FakeBilling();
        $this->people = new InMemoryPeople();
        $this->fakes = new EntryFakes($this->people);
        $this->links = new PersonLinks();
        $this->socleEnrollments = new InMemoryEnrollments();
        $this->admissionsClock = new AdmissionsClock('2026-10-13');
        $this->enrolmentClock = new EnrolmentClock('2026-10-13');
        $this->bootCurriculum();
        $this->curriculum = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $this->curriculum['id'] . '/publish');

        $this->people->add(self::T, self::MARIE, 'Marie', 'Ngo', '+237690000001');
        $this->links->link(self::T, self::CAND, self::MARIE);
        $this->assign(self::T, self::CAND, self::E, 'higher.application.submit', 'establishment');
        foreach (['higher.application.manage', 'higher.admission.read', 'higher.enrol', 'higher.enrolment.read'] as $permission) {
            $this->assign(self::T, self::SCOL, self::E, $permission, 'org_unit', $this->org['school'], 'higher_scolarite');
        }
        foreach (['higher.admission.read', 'higher.admission.decide', 'higher.enrolment.read', 'higher.enrolment.validate', 'higher.enrolment.derogate'] as $permission) {
            $this->assign(self::T, self::DIR, self::E, $permission, 'org_unit', $this->org['school'], 'higher_dir_ecole');
        }
    }

    protected function bootHigher(array $overrides = [], bool $migrate = false): void
    {
        parent::bootHigher($overrides + [
            PersonDirectory::class => $this->people,
            AccountProvisioner::class => $this->fakes,
            UserPersonLink::class => $this->links,
            InvoiceIssuer::class => $this->billing,
            PaymentRequester::class => $this->billing,
            StudentAccounts::class => $this->fakes,
            FinancialHolds::class => $this->fakes,
            HigherDocumentIssuer::class => $this->fakes,
            HigherNotifier::class => $this->fakes,
            EnrollmentDirectory::class => $this->socleEnrollments,
            AdmissionsClock::class => $this->admissionsClock,
            EnrolmentClock::class => $this->enrolmentClock,
        ], $migrate);
    }

    protected function tearDown(): void
    {
        unset($this->billing, $this->fakes, $this->people, $this->links, $this->socleEnrollments, $this->admissionsClock, $this->enrolmentClock);
        parent::tearDown();
    }

    /** Campagne ouverte du niveau 1 (ou du niveau donné) de la filière CF. */
    protected function openCampaign(int $seats = 2, int $fee = 10000, ?string $levelId = null, string $mode = 'file'): string
    {
        $id = (string) $this->ok('POST', '/higher/admissions/campaigns', [
            'level_id' => $levelId ?? $this->org['l1'], 'academic_year_id' => self::YEAR, 'opens_on' => '2026-03-01', 'closes_on' => '2026-10-31',
            'seats' => $seats, 'application_fee_xaf' => $fee, 'selection_mode' => $mode,
        ], self::SCOL)['id'];
        $this->ok('POST', '/higher/admissions/campaigns/' . $id . '/open', null, self::SCOL);

        return $id;
    }

    /**
     * Candidature au guichet complète (pièces déposées, frais payés en espèces) d'une nouvelle personne ou d'une fiche existante.
     *
     * @return array<string, mixed>
     */
    protected function completeApplication(string $campaignId, string $lastName, string $diploma = 'bac', ?string $personId = null): array
    {
        $who = $personId !== null
            ? ['person_id' => $personId]
            : ['identity' => ['first_name' => 'Candidat', 'last_name' => $lastName, 'phone' => '+23769' . substr(md5($lastName), 0, 7)]];
        $application = $this->ok('POST', '/higher/admissions/applications', [
            'campaign_id' => $campaignId, 'diploma' => $diploma, 'diploma_series' => 'C', 'diploma_year' => 2026, 'diploma_mention' => 'assez_bien',
        ] + $who, self::SCOL);
        $id = (string) $application['id'];
        foreach ($application['documents'] as $document) {
            $this->ok('POST', '/higher/admissions/applications/' . $id . '/documents', ['requirement_code' => $document['requirement_code'], 'document_ref' => 'scan-' . $document['requirement_code']], self::SCOL);
        }
        $this->ok('POST', '/higher/admissions/applications/' . $id . '/submit', null, self::SCOL);

        return $this->ok('POST', '/higher/admissions/applications/' . $id . '/fee-payment', ['channel' => 'cash'], self::SCOL);
    }

    /** Vérifie les pièces bloquantes puis décide « admis » (R04). */
    protected function admit(string $applicationId): void
    {
        $application = $this->ok('GET', '/higher/admissions/applications/' . $applicationId, null, self::SCOL);
        foreach ($application['documents'] as $document) {
            if ($document['blocking'] && $document['status'] === 'uploaded') {
                $this->ok('POST', '/higher/admissions/applications/' . $applicationId . '/documents/' . $document['id'] . '/verify', null, self::SCOL);
            }
        }
        $this->ok('POST', '/higher/admissions/applications/' . $applicationId . '/decision', ['decision' => 'admitted', 'reason' => 'Dossier conforme.'], self::DIR);
    }

    /** Compte de scolarité de l'année et de la filière : frais d'inscription (50 000) payés ou non, pension payée. */
    protected function registrationAccount(string $personId, string $year, string $programId, bool $registrationPaid = true, int $tuitionPaid = 0): void
    {
        $invoice = $this->billing->create(self::T, self::E, $personId, 'acc-' . $personId . '-' . $year, [
            ['hi.inscription', 50000, $registrationPaid ? 50000 : 0], ['hi.pension', 450000, $tuitionPaid],
        ]);
        $this->fakes->openAccount(self::E, $personId, $year, $programId, $invoice->id);
    }
}
