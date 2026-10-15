<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Academic;

use Scholaris\Modules\Apps\Higher\Academic\Application\ExpiryAlertService;
use Scholaris\Modules\Apps\Higher\Academic\Console\ExpiryAlertsCommand;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Higher\Academic\AcademicTestCase;

/**
 * EX-ACA-07 (T224) : alertes d'expiration à 90, 30 et 7 jours des autorisations MINESUP et des conventions de tutelle, envoyées
 * par Messenger ; chaque seuil une seule fois par échéance ; client suspendu sauté (WriteGate). Horloge de test : la date du jour
 * est passée à la tâche planifiée.
 */
final class ExpiryAlertsTest extends AcademicTestCase
{
    /** @var array{school: string, department: string, program: string, l1: string, l2: string, g1: string} */
    private array $org;

    protected function setUp(): void
    {
        $this->bootAcademic();
        $this->org = $this->buildOrg();
        $this->settings->set(self::E, 'higher.alerts.expiry_recipients', ['person-dg', 'person-dir-acad']);
        // Échéance le 2027-01-11 : 90 jours après le 2026-10-13.
        $this->authorize($this->org['program'], null, '2024-09-01', '2027-01-11', 'ARR-2024-0042');
    }

    /** @return array{alerts: int, messages: int, skipped_tenants: int} */
    private function alerts(string $day): array
    {
        return $this->service(ExpiryAlertService::class)->run($day);
    }

    public function testSeuils90_30_7UneSeuleFoisChacun(): void
    {
        self::assertSame(0, $this->alerts('2026-10-12')['alerts'], 'J-91 : rien.');

        $first = $this->alerts('2026-10-13');
        self::assertSame([1, 2], [$first['alerts'], $first['messages']]);
        self::assertSame('higher.expiry_alert', $this->messenger->sent[0]['template']);
        self::assertSame(['90', '2027-01-11', 'ARR-2024-0042'], [$this->messenger->sent[0]['variables']['offset'], $this->messenger->sent[0]['variables']['due_date'], $this->messenger->sent[0]['variables']['reference']]);
        self::assertStringContainsString('Comptabilité et finance', (string) $this->messenger->sent[0]['variables']['label']);
        self::assertSame(['person-dg', 'person-dir-acad'], array_column($this->messenger->sent, 'person'));

        self::assertSame(0, $this->alerts('2026-10-13')['alerts'], 'Même jour : déjà envoyée.');
        self::assertSame(0, $this->alerts('2026-11-20')['alerts'], 'Entre 90 et 30 jours : rien de nouveau.');
        self::assertSame(1, $this->alerts('2026-12-12')['alerts'], 'J-30.');
        self::assertSame('30', $this->messenger->sent[2]['variables']['offset']);
        self::assertSame(1, $this->alerts('2027-01-04')['alerts'], 'J-7.');
        self::assertSame(0, $this->alerts('2027-01-12')['alerts'], 'Échéance passée.');
        self::assertCount(6, $this->messenger->sent);
    }

    public function testRattrapageSansRafaleEtConventionDeTutelle(): void
    {
        $this->tutelage($this->org['program'], self::YEAR, 'Université de Douala', '2026-10-20');

        $result = $this->alerts('2026-10-13');

        self::assertSame(2, $result['alerts'], 'Autorisation à 90 jours, convention à 7 jours.');
        $offsets = array_map(static fn (array $m): string => (string) $m['variables']['offset'], $this->messenger->sent);
        self::assertSame(['90', '90', '7', '7'], $offsets, 'Convention : un seul message au plus petit seuil franchi, pas de rafale 90/30/7.');
        self::assertStringContainsString('Convention de tutelle', (string) $this->messenger->sent[2]['variables']['label']);
        self::assertSame(0, $this->alerts('2026-10-14')['alerts']);
    }

    public function testClientSuspenduSauteEtSansDestinataireRienNEstMarque(): void
    {
        $this->applications->set(self::T, ApplicationAccess::SUSPENDED);
        self::assertSame(['alerts' => 0, 'messages' => 0, 'skipped_tenants' => 1], $this->alerts('2026-10-13'));

        $this->applications->set(self::T, ApplicationAccess::ACTIVE);
        $this->settings->set(self::E, 'higher.alerts.expiry_recipients', []);
        self::assertSame(0, $this->alerts('2026-10-13')['alerts']);

        $this->settings->set(self::E, 'higher.alerts.expiry_recipients', ['person-dg']);
        self::assertSame(1, $this->alerts('2026-10-13')['alerts'], 'Seuil non marqué faute de destinataire : envoyé dès qu\'il y en a un.');
    }

    public function testSeuilsParametresEtCommandePlanifiee(): void
    {
        $this->settings->set(self::E, 'higher.alerts.expiry_days', [60, 15]);
        self::assertSame(0, $this->alerts('2026-10-13')['alerts'], 'J-90 hors seuils paramétrés.');

        $command = $this->kernel->container()->get('console.command.' . ExpiryAlertsCommand::NAME);
        self::assertInstanceOf(ExpiryAlertsCommand::class, $command);
        ob_start();
        $code = $command->run(['2026-11-12']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('1 alerte(s)', $output);
        self::assertSame('60', $this->messenger->sent[0]['variables']['offset']);
    }
}
