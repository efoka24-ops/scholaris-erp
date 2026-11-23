<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use Scholaris\Modules\Apps\Secondary\Services\Infrastructure\HealthCipher;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T956 (TST-T7) : un établissement de type « autre » n'a aucune valeur par défaut au-delà du noyau ; l'assistant exige propriété,
 * cycles, enseignement et titre du chef ; seuls les modules choisis à la main répondent.
 * T957 (EX-SEC-04, D10) : un module coupé alors qu'il porte des données passe en lecture seule (lectures 200, écritures 409), le
 * journal des changements est complet et rien n'est supprimé ; réactivé, il redevient modifiable. Tout passe par l'API.
 */
final class OtherTypeAndModuleCutTest extends SecondaryTestCase
{
    private const MANAGER = 'resp-services-a';

    private InMemoryEnrollments $enrollments;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->bootSecondary([EnrollmentDirectory::class => $this->enrollments, HealthCipher::class => new HealthCipher('cle-de-test-fictive')]);
    }

    public function testTypeAutreSansValeurParDefautEtModulesChoisisALaMain(): void
    {
        $school = $this->factory->establishment(self::TA, 'autre');
        $this->grant(self::TA, self::ADMIN, $school, ['secondary.profile.manage']);
        $this->grant(self::TA, self::MANAGER, $school, ['secondary.services.manage', 'secondary.billing.manage']);

        $draft = $this->ok('GET', '/secondary/setup/profile', null, self::ADMIN, self::TA, $school);
        self::assertFalse($draft['stored']);
        self::assertSame([null, [], [], null], [$draft['ownership'], $draft['cycles'], $draft['teaching_kinds'], $draft['head_title']]);
        self::assertSame([], array_values(array_filter($draft['modules'], static fn (array $m): bool => $m['default_for_type'] || $m['state'] === 'active')), 'Aucun module par défaut.');

        [$status, $json] = $this->call('POST', '/secondary/setup/profile/open', ['reason' => 'Ouverture'], self::ADMIN, self::TA, $school);
        self::assertSame(422, $status);
        foreach (['ownership', 'cycles', 'teaching_kinds', 'head_title'] as $field) {
            self::assertArrayHasKey($field, $json['errors'] ?? [], $field . ' exigé par l\'assistant');
        }

        $opened = $this->ok('POST', '/secondary/setup/profile/open', [
            'ownership' => 'private_faith', 'cycles' => ['first'], 'teaching_kinds' => ['general'], 'head_title' => 'directeur',
            'modules' => ['canteen_transport', 'infirmary'], 'reason' => 'Ouverture d\'un établissement atypique (fictif)',
        ], self::ADMIN, self::TA, $school);
        $active = array_column(array_values(array_filter($opened['modules'], static fn (array $m): bool => $m['state'] === 'active')), 'code');
        self::assertSame(['canteen_transport', 'infirmary'], $active, 'Seulement les modules choisis.');

        self::assertSame(200, $this->call('GET', '/secondary/services/canteen-transport/offers', null, self::MANAGER, self::TA, $school)[0]);
        foreach (['/secondary/services/boarding/offers', '/secondary/fees/payment-plans', '/secondary/fees/catalog'] as $path) {
            [$code, $body] = $this->call('GET', $path, null, self::MANAGER, self::TA, $school);
            self::assertSame([409, 'module_not_enabled'], [$code, $body['code'] ?? null], $path);
        }
    }

    public function testModuleCoupeAvecDonneesEnLectureSeuleEtJournalComplet(): void
    {
        $school = $this->factory->opened(self::TA, 'lycee_prive');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $school, 'classe-1');
        $this->grant(self::TA, self::ADMIN, $school, ['secondary.profile.manage']);
        $this->grant(self::TA, self::MANAGER, $school, ['secondary.services.manage']);
        $base = ['second_cycle', 'orientation', 'official_exams', 'fees_private', 'authorizations', 'payroll_simple'];

        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => [...$base, 'canteen_transport'], 'reason' => 'Ouverture de la cantine'], self::ADMIN, self::TA, $school);
        $offer = $this->ok('POST', '/secondary/services/canteen-transport/offers', ['service_kind' => 'canteen', 'label' => 'Demi-pension', 'amount_xaf' => 15000], self::MANAGER, self::TA, $school);
        $subscription = $this->ok('POST', '/secondary/services/canteen-transport/subscriptions', ['offer_id' => $offer['id'], 'person_id' => 'eleve-1', 'starts_on' => '2026-10-26'], self::MANAGER, self::TA, $school);

        $cut = $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => $base, 'reason' => 'Cantine confiée à un prestataire'], self::ADMIN, self::TA, $school);
        self::assertSame(['canteen_transport'], $cut['read_only_modules']);
        $state = array_column($cut['modules'], 'state', 'code');
        self::assertSame('read_only', $state['canteen_transport']);

        $offers = $this->call('GET', '/secondary/services/canteen-transport/offers', null, self::MANAGER, self::TA, $school);
        self::assertSame(200, $offers[0], 'Lecture permise.');
        self::assertSame([$offer['id']], array_column($offers[1]['data'], 'id'), 'Rien n\'est supprimé.');
        $subs = $this->call('GET', '/secondary/services/canteen-transport/subscriptions', null, self::MANAGER, self::TA, $school)[1]['data'];
        self::assertSame([[$subscription['id'], 'active']], array_map(static fn (array $s): array => [$s['id'], $s['status']], $subs));
        foreach ([
            ['POST', '/secondary/services/canteen-transport/offers', ['service_kind' => 'canteen', 'label' => 'Autre', 'amount_xaf' => 1]],
            ['PATCH', '/secondary/services/canteen-transport/offers/' . $offer['id'], ['label' => 'Modifiée']],
            ['POST', '/secondary/services/canteen-transport/subscriptions/' . $subscription['id'] . '/end', ['ends_on' => '2026-11-01', 'reason' => 'Fin']],
        ] as [$method, $path, $body]) {
            [$code, $json] = $this->call($method, $path, $body, self::MANAGER, self::TA, $school);
            self::assertSame([409, 'module_not_enabled'], [$code, $json['code'] ?? null], $method . ' ' . $path);
        }
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM se_service_subscriptions')->fetchColumn());

        $journal = $this->call('GET', '/secondary/setup/profile/changes?per_page=50', null, self::ADMIN, self::TA, $school)[1]['data'];
        self::assertSame(['opened', 'modules', 'modules'], array_column($journal, 'change_kind'));
        self::assertSame('Cantine confiée à un prestataire', $journal[2]['reason']);

        $this->ok('POST', '/secondary/setup/profile/changes', ['kind' => 'modules', 'value' => [...$base, 'canteen_transport'], 'reason' => 'Reprise en régie'], self::ADMIN, self::TA, $school);
        $this->ok('POST', '/secondary/services/canteen-transport/subscriptions/' . $subscription['id'] . '/end', ['ends_on' => '2026-11-01', 'reason' => 'Fin'], self::MANAGER, self::TA, $school);
    }
}
