<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Notifications;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Notifications\Domain\NotificationCatalog;

/** Catalogue NOT-01 à NOT-11 : textes FR/EN, variables de la liste blanche du Messenger, clés de modèle valides. */
final class NotificationCatalogTest extends TestCase
{
    public function testOnzeNotificationsBilinguesAvecDesVariablesPermises(): void
    {
        self::assertSame(['NOT-01', 'NOT-02', 'NOT-03', 'NOT-04', 'NOT-05', 'NOT-06', 'NOT-07', 'NOT-08', 'NOT-09', 'NOT-10', 'NOT-11'], array_keys(NotificationCatalog::ALL));
        foreach (NotificationCatalog::ALL as $code => $entry) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_.]*$/', $entry['key'], $code);
            self::assertLessThanOrEqual(60, strlen($entry['key']));
            foreach (['fr', 'en'] as $locale) {
                self::assertNotSame('', $entry[$locale]);
                self::assertSame([], array_diff(NotificationCatalog::placeholders($entry[$locale]), NotificationCatalog::MESSENGER_VARIABLES), $code . ' ' . $locale);
            }
            self::assertSame(NotificationCatalog::placeholders($entry['fr']), NotificationCatalog::placeholders($entry['en']), $code . ' : mêmes variables en FR et EN');
            self::assertCount(count($entry['channels']) * 2, NotificationCatalog::messengerTemplates($code));
        }
        self::assertContains(NotificationCatalog::GUARANTORS_WITH_CONSENT, NotificationCatalog::ALL['NOT-06']['recipients']);
        self::assertContains(NotificationCatalog::GUARANTORS_WITH_CONSENT, NotificationCatalog::ALL['NOT-07']['recipients']);
    }
}
