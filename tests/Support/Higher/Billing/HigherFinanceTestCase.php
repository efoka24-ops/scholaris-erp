<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Billing;

use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Fixtures\Higher\HigherOrgFixture;
use Tests\Support\Billing\FixedClock;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryEstablishments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Higher\HigherTestCase;

/**
 * Base des tests FIN, DOC, notifications et portail étudiant de l'IPES : vraie base SQLite migrée (socle Billing et Documents
 * compris), horloge figée, personnes, inscriptions, messagerie, paramètres (avec portée école) et lien compte-personne en mémoire.
 *
 * Acteurs (client A) : `comptable` (billing.manage sur l'établissement), `comptable2` (second validateur), `caissier`
 * (billing.cash), `scolarite-ec` (documents.issue sur l'école EC), `admin` (documents.templates.manage), `marie` (étudiante,
 * self.read), `papa` (garant de marie). Établissement A : école EC (filière CF, niveau CF-L1) et école IT.
 */
abstract class HigherFinanceTestCase extends HigherTestCase
{
    protected const T = HigherOrgFixture::TENANT_A;
    protected const E = HigherOrgFixture::ESTABLISHMENT_A;
    protected const YEAR = 'ay-2026';

    protected FixedClock $clock;
    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected RecordingMessenger $messenger;
    protected OrgSettings $settings;
    protected InMemoryEstablishments $establishments;
    protected PersonLinks $links;
    protected Consents $consents;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $definitions liaisons de conteneur supplémentaires (fournisseurs de types, langues…)
     */
    protected function bootFinance(array $settings = [], array $definitions = []): void
    {
        $this->clock = new FixedClock('2026-10-01T09:00:00+01:00');
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->messenger = new RecordingMessenger();
        $this->settings = new OrgSettings($settings);
        $this->establishments = new InMemoryEstablishments();
        $this->establishments->add(self::T, self::E, 'Institut Supérieur du Littoral', 'higher');
        $this->establishments->add(HigherOrgFixture::TENANT_B, HigherOrgFixture::ESTABLISHMENT_B, 'Autre IPES', 'higher');
        $this->links = new PersonLinks();
        $this->consents = new Consents();

        $this->bootHigher([
            Clock::class => $this->clock,
            PersonDirectory::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            Messenger::class => $this->messenger,
            SettingsReader::class => $this->settings,
            OrgUnitSettingsReader::class => $this->settings,
            EstablishmentDirectory::class => $this->establishments,
            PaymentGateway::class => new SimulatedPaymentGateway('secret-de-test'),
            UserPersonLink::class => $this->links,
            ConsentChecker::class => $this->consents,
        ] + $definitions, true);

        $this->people->add(self::T, 'marie', 'Marie', 'Ngo', '+237690000001');
        $this->people->add(self::T, 'papa', 'Paul', 'Ngo', '+237690000002');
        $this->people->add(self::T, 'yann', 'Yann', 'Eto', '+237690000003');
        $this->people->link(self::T, 'papa', 'marie');
        $this->people->add(HigherOrgFixture::TENANT_B, 'autre', 'Autre', 'Client');
        $this->links->link(self::T, 'marie', 'marie');
        $this->links->link(self::T, 'papa', 'papa');

        $this->assign(self::T, 'comptable', self::E, 'billing.manage', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'comptable', self::E, 'billing.read', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'comptable', self::E, 'higher.billing.read', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'comptable', self::E, 'documents.issue', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'comptable', self::E, 'higher.quitus.issue', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'comptable2', self::E, 'billing.manage', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'caissier', self::E, 'billing.cash', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'caissier', self::E, 'billing.read', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'scolarite-ec', self::E, 'documents.issue', Scope::ORG_UNIT, 'EC');
        $this->assign(self::T, 'scolarite-ec', self::E, 'higher.document.read', Scope::ORG_UNIT, 'EC');
        $this->assign(self::T, 'admin', self::E, 'documents.templates.manage', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'marie', self::E, 'self.read', Scope::ESTABLISHMENT);
        $this->assign(self::T, 'papa', self::E, 'self.read', Scope::ESTABLISHMENT);
    }

    /** Frais du catalogue du socle (codes stables HigherFees) créés par l'API du socle. */
    protected function seedFeeCatalog(): void
    {
        foreach ([
            ['registration', 'hi.inscription', 'Frais d\'inscription', 50000],
            ['tuition', 'hi.pension', 'Pension', 450000],
            ['application_fee', 'hi.dossier', 'Frais de dossier', 15000],
            ['national_exam', 'hi.examen_national', 'Frais d\'examen national', 30000],
        ] as [$kind, $code, $name, $amount]) {
            [$response] = $this->api('POST', '/fee-items', 'comptable', self::T, self::E, ['kind' => $kind, 'code' => $code, 'name' => $name, 'amount_xaf' => $amount]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    /**
     * Grille du CDC : inscription 50 000 + pension 450 000 en 3 tranches (15 octobre, 15 janvier, 15 avril).
     *
     * @return array<string, mixed>
     */
    protected function cdcGrid(): array
    {
        [$response, $json] = $this->api('POST', '/higher/billing/fee-grids', 'comptable', self::T, self::E, [
            'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'study_mode' => 'day',
            'label' => 'L1 Comptabilité et finance, cours du jour',
            'fees' => [['fee_code' => 'hi.inscription', 'amount_xaf' => 50000], ['fee_code' => 'hi.pension', 'amount_xaf' => 450000]],
            'start_year' => 2026,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array<string, mixed> */
    protected function openAccount(string $personId = 'marie', array $extra = []): array
    {
        [$response, $json] = $this->api('POST', '/higher/billing/accounts', 'comptable', self::T, self::E, $extra + [
            'person_id' => $personId, 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'study_mode' => 'day',
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /**
     * Installe au socle les types et modèles du catalogue Higher (comme le ferait l'administrateur fonctionnel avec le champ
     * `socle` de GET /higher/documents/catalog), puis les profils documentaires des écoles EC et IT.
     */
    protected function installDocuments(): void
    {
        [$response, $catalog] = $this->api('GET', '/higher/documents/catalog', 'admin', self::T, self::E);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        foreach ($catalog['data'] as $type) {
            if ($type['socle'] === null) {
                continue;
            }
            [$created] = $this->api('POST', '/document-types', 'admin', self::T, self::E, array_filter($type['socle']['type'], static fn ($v): bool => $v !== null));
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
            foreach ($type['socle']['templates'] as $template) {
                [$stored, $json] = $this->api('POST', '/document-templates', 'admin', self::T, self::E, ['type_code' => $type['socle']['type']['code']] + $template);
                self::assertSame(201, $stored->getStatusCode(), (string) $stored->getBody());
                [$validated] = $this->api('POST', '/document-templates/' . $json['data']['id'] . '/validate', 'admin', self::T, self::E, []);
                self::assertSame(200, $validated->getStatusCode(), (string) $validated->getBody());
            }
        }
        foreach ([['EC', 'École de commerce', 'fr', '#0B5394'], ['IT', 'School of Computing', 'en', '#38761D']] as [$school, $name, $locale, $color]) {
            [$profile] = $this->api('PUT', '/higher/documents/schools/' . $school . '/profile', 'admin', self::T, self::E, [
                'school_code' => $school, 'display_name' => $name, 'header_text' => 'Arrêté d\'autorisation : [N° D\'ARRÊTÉ]',
                'logo_url' => 'https://cdn.example.org/' . strtolower($school) . '.png', 'primary_color' => $color, 'default_locale' => $locale,
            ]);
            self::assertSame(200, $profile->getStatusCode(), (string) $profile->getBody());
        }
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function issueDocument(string $user, string $code, string $personId, string $school, array $data = [], ?string $program = null, ?string $locale = null): array
    {
        $body = ['code' => $code, 'person_id' => $personId, 'school_id' => $school, 'data' => $data === [] ? new \stdClass() : $data];
        if ($program !== null) {
            $body['program_id'] = $program;
        }
        if ($locale !== null) {
            $body['locale'] = $locale;
        }
        $response = $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('POST', '/api/v1/higher/documents/issue', [
            'Authorization' => 'Bearer ' . $user . ':' . self::T, 'X-Establishment-Id' => self::E, 'Content-Type' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    protected function pay(string $accountId, string $user, int $amount, string $channel, string $key, ?string $reference = null): array
    {
        $body = ['amount_xaf' => $amount, 'channel' => $channel];
        if ($reference !== null) {
            $body['external_reference'] = $reference;
        }
        $response = $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('POST', '/api/v1/higher/billing/accounts/' . $accountId . '/payments', [
            'Authorization' => 'Bearer ' . $user . ':' . self::T, 'X-Establishment-Id' => self::E, 'Content-Type' => 'application/json',
            'Idempotency-Key' => $key,
        ], json_encode($body, JSON_THROW_ON_ERROR)));
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}
