<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Services;

use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Primary\FakeClassResults;
use Tests\Support\Primary\PrimaryTestCase;
use Tests\Support\Primary\RecordingInvoices;

/**
 * C-104 : services du primaire privé, côté écran : élèves à abonner (candidats), présence au service (une ligne par abonnement et par jour,
 * correction sans doublon, abonnés actifs ce jour-là seulement, jamais un jour futur), droits et isolation entre clients. Données fictives.
 */
final class ServiceAttendanceTest extends PrimaryTestCase
{
    private string $plan;

    protected function setUp(): void
    {
        $enrollments = new InMemoryEnrollments();
        $people = new InMemoryPeople();
        $this->bootPrimary([
            EnrollmentDirectory::class => $enrollments, PersonDirectory::class => $people, InvoiceIssuer::class => new RecordingInvoices(), ClassResults::class => new FakeClassResults(),
        ], '2026-10-21');
        $this->seedYear(self::TA, self::EA);
        $level = $this->makeLevel(self::TA, self::EA, 'CE1', 1);
        $this->ok('POST', '/primary/level-profiles', ['level_structure_id' => $level['level'], 'sort_order' => 1]);
        foreach (['a1', 'a2', 'a3'] as $id) {
            $people->add(self::TA, $id, 'Prénom', 'Nom' . $id);
            $enrollments->add(self::TA, 'en' . $id, $id, self::EA, $level['classes'][0], self::YEAR);
        }
        $enrollments->add(self::TB, 'enb', 'b1', self::EB, null, self::YEAR);
        $people->add(self::TB, 'b1', 'Autre', 'Client');
        $this->plan = (string) $this->ok('POST', '/primary/service-plans', ['service_kind' => 'canteen', 'label' => 'Cantine midi', 'fee_code' => 'CANTINE', 'billing_cycle' => 'term'])['id'];
        $this->ok('POST', "/primary/service-plans/{$this->plan}/subscriptions", ['person_id' => 'a1', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-01']);
        $this->ok('POST', "/primary/service-plans/{$this->plan}/subscriptions", ['person_id' => 'a2', 'academic_year_id' => self::YEAR, 'starts_on' => '2026-10-20']);
    }

    public function testLesCandidatsSontLesElevesInscritsAvecLeurAbonnement(): void
    {
        $items = array_column($this->ok('GET', "/primary/service-plans/{$this->plan}/candidates")['items'], null, 'person_id');

        self::assertSame(['a1', 'a2', 'a3'], array_keys($items), 'Les élèves d\'un autre client sont absents.');
        self::assertSame([true, true, false], [$items['a1']['subscribed'], $items['a2']['subscribed'], $items['a3']['subscribed']]);
        self::assertSame(self::YEAR, $items['a3']['academic_year_id']);
        self::assertSame(404, $this->call('GET', '/primary/service-plans/inconnu/candidates')[0]);
    }

    public function testPresenceSaisieCorrigeeEtSansDoublon(): void
    {
        $day = $this->ok('GET', "/primary/service-plans/{$this->plan}/attendance?date=2026-10-21");
        self::assertSame(['a1', 'a2'], array_column($day['items'], 'person_id'));
        self::assertSame([null, null], array_column($day['items'], 'present'), 'Rien de saisi : null, jamais « absent » par défaut.');
        $subscriptions = array_column($this->ok('GET', "/primary/service-plans/{$this->plan}/subscriptions")['items'], 'id', 'person_id');

        $saved = $this->ok('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-21', 'entries' => [
            ['subscription_id' => $subscriptions['a1'], 'present' => true], ['subscription_id' => $subscriptions['a2'], 'present' => false],
        ]]);
        self::assertSame(2, $saved['saved']);
        $this->ok('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-21', 'entries' => [['subscription_id' => $subscriptions['a2'], 'present' => true]]]);

        $after = array_column($this->ok('GET', "/primary/service-plans/{$this->plan}/attendance?date=2026-10-21")['items'], 'present', 'person_id');
        self::assertSame(['a1' => true, 'a2' => true], $after, 'Correction du jour : une seule ligne par abonnement.');
        $pdo = $this->kernel->container()->get(\Scholaris\Core\Database\ConnectionFactory::class)->pdo();
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM pri_service_attendance')->fetchColumn());
    }

    public function testSeulsLesAbonnesActifsCeJourLaSontAcceptes(): void
    {
        $subscriptions = array_column($this->ok('GET', "/primary/service-plans/{$this->plan}/subscriptions")['items'], 'id', 'person_id');
        $before = $this->ok('GET', "/primary/service-plans/{$this->plan}/attendance?date=2026-10-10");
        self::assertSame(['a1'], array_column($before['items'], 'person_id'), 'a2 n\'est abonné qu\'à partir du 20 octobre.');

        [$status] = $this->call('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-10', 'entries' => [['subscription_id' => $subscriptions['a2'], 'present' => true]]]);
        self::assertSame(422, $status);
        [$future] = $this->call('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-22', 'entries' => [['subscription_id' => $subscriptions['a1'], 'present' => true]]]);
        self::assertSame(422, $future, 'Pas de présence pour demain.');
        [$bad] = $this->call('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-21', 'entries' => [['subscription_id' => $subscriptions['a1'], 'present' => 'oui']]]);
        self::assertSame(422, $bad);
        self::assertSame(422, $this->call('GET', "/primary/service-plans/{$this->plan}/attendance?date=hier")[0]);

        $this->ok('POST', "/primary/service-subscriptions/{$subscriptions['a1']}/end", ['ends_on' => '2026-10-15']);
        self::assertSame(['a2'], array_column($this->ok('GET', "/primary/service-plans/{$this->plan}/attendance?date=2026-10-21")['items'], 'person_id'), 'Abonnement terminé le 15 : plus attendu le 21.');
    }

    public function testDroitsEtIsolationEntreClients(): void
    {
        $this->assign(self::TA, 'lecteur', self::EA, 'primary.services.read', Scope::ESTABLISHMENT);
        self::assertSame(200, $this->call('GET', "/primary/service-plans/{$this->plan}/attendance", null, 'lecteur')[0]);
        self::assertSame(200, $this->call('GET', "/primary/service-plans/{$this->plan}/candidates", null, 'lecteur')[0]);
        self::assertSame(403, $this->call('PUT', "/primary/service-plans/{$this->plan}/attendance", ['date' => '2026-10-21', 'entries' => []], 'lecteur')[0], 'Lecture seule : pas de saisie.');

        $this->grantAll(self::TB, 'directeur-b', self::EB);
        self::assertSame(404, $this->call('GET', "/primary/service-plans/{$this->plan}/attendance", null, 'directeur-b', self::TB, self::EB)[0], 'Forfait d\'un autre client introuvable.');
        self::assertSame(404, $this->call('GET', "/primary/service-plans/{$this->plan}/candidates", null, 'directeur-b', self::TB, self::EB)[0]);
    }
}
