<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Platform\Console\AdminCreateCommand;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;

/** Règles pures : calendrier, essai, relances, états, couverture d'un paiement, arguments de la commande console. */
final class SubscriptionRulesTest extends TestCase
{
    public function testCalendrier(): void
    {
        self::assertSame('2027-01-06', Calendar::addDays('2026-10-09', 89));
        self::assertSame('2027-02-28', Calendar::addMonths('2027-01-31', 1));
        self::assertSame('2028-02-29', Calendar::addMonths('2028-01-31', 1));
        self::assertSame(-3, Calendar::daysBetween('2027-01-06', '2027-01-03'));
        self::assertSame('06/01/2027', Calendar::display('2027-01-06'));
        self::assertFalse(Calendar::isDate('2027-02-30'));
    }

    public function testEssaiDeQuatreVingtDixJours(): void
    {
        self::assertSame('2027-01-06', Subscription::trialEnd('2026-10-09', 90));
        self::assertSame('2027-01-06', Subscription::dueOn(null, '2027-01-06'));
        self::assertSame('2027-03-31', Subscription::dueOn('2027-03-31', '2027-01-06'));
    }

    public function testRelancesUneParPalierEtRattrapageDUneExecutionManquee(): void
    {
        self::assertNull(Subscription::reminderFor(15, []));
        self::assertSame(['stage' => 14, 'covered' => [14]], Subscription::reminderFor(14, []));
        self::assertNull(Subscription::reminderFor(13, [14]));
        self::assertSame(['stage' => 3, 'covered' => [14, 3]], Subscription::reminderFor(2, [14]), 'J-3 manqué : rattrapé à J-2.');
        self::assertSame(['stage' => 0, 'covered' => [14, 3, 0]], Subscription::reminderFor(0, []), 'Une seule relance même si les précédentes ont été manquées.');
        self::assertNull(Subscription::reminderFor(-1, []));
    }

    public function testEtats(): void
    {
        self::assertSame('trial', Subscription::nextState('trial', '2027-01-06', '2027-01-06', 15));
        self::assertSame('past_due', Subscription::nextState('trial', '2027-01-07', '2027-01-06', 15));
        self::assertSame('past_due', Subscription::nextState('active', '2027-01-21', '2027-01-06', 15));
        self::assertSame('suspended', Subscription::nextState('past_due', '2027-01-22', '2027-01-06', 15));
        self::assertSame('closed', Subscription::nextState('closed', '2030-01-01', '2027-01-06', 15));
    }

    public function testCouvertureDUnPaiement(): void
    {
        self::assertSame(['from' => '2027-01-07', 'until' => '2027-02-06'], Subscription::coverage('2026-12-01', '2027-01-06', 1));
        self::assertSame(['from' => '2027-02-01', 'until' => '2027-03-31'], Subscription::coverage('2027-02-01', '2027-01-06', 2));
    }

    public function testArgumentsDePlatformAdminCreate(): void
    {
        self::assertSame(
            ['identifier' => 'emm.foka@gmail.com', 'first_name' => 'Emmanuel', 'last_name' => 'Foka Ndjock'],
            AdminCreateCommand::parse(['emm.foka@gmail.com', 'Emmanuel', 'Foka', 'Ndjock']),
        );
        self::assertSame('Foka', AdminCreateCommand::parse(['"+237690000000"', 'Emmanuel', '"Foka"'])['last_name'] ?? null);
        self::assertNull(AdminCreateCommand::parse(['emm.foka@gmail.com', 'Emmanuel']));
    }

    public function testManifesteInvalideRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApplicationManifest('higher', 'IPES', 'university', 'available', '1.0.0', []);
    }
}
